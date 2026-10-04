<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

class FlushApiUsage extends Command
{
    /**
     * Seconds a claimed buffer stays locked before another run may retry it.
     */
    private const CLAIM_TTL_SECONDS = 300;

    /**
     * Failed flushes of one buffer after which the next attempt isolates the
     * rows the database rejects, instead of failing the whole buffer again.
     * Earlier failures are retried as they are, because most are transient.
     */
    private const ISOLATE_AFTER_ATTEMPTS = 3;

    /**
     * The rejected list keeps at most this many events, for this many seconds.
     */
    private const REJECTED_LIMIT = 1000;

    private const REJECTED_TTL_SECONDS = 604800;

    /**
     * Driver error codes that mean "this row's data is unacceptable" although
     * MySQL reports them under the generic SQLSTATE HY000.
     */
    private const MYSQL_DATA_ERROR_CODES = [1264, 1265, 1292, 1366, 1406];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:flush';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Flush buffered API usage events from Redis into the database';

    private ?Connection $connection = null;

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! UsageConfig::enabled()) {
            return self::SUCCESS;
        }

        if (! UsageConfig::usesRedis()) {
            $this->info('The database driver writes requests directly; there is nothing to flush.');

            return self::SUCCESS;
        }

        $recovered = $this->recoverAbandonedBuffers();
        $flushed = 0;

        foreach ($this->pendingKeys() as $key) {
            $flushed += $this->claimAndDrain($key);
        }

        $total = $flushed + $recovered;

        $this->info($recovered > 0
            ? "Flushed {$total} API usage events ({$recovered} recovered from a previous run)."
            : "Flushed {$total} API usage events.");

        return self::SUCCESS;
    }

    /**
     * Every minute buffer the middleware has registered, oldest first.
     *
     * Reading the registry rather than scanning a window of minutes means an
     * outage of any length cannot strand a buffer: it stays registered until it
     * is claimed or its TTL removes it.
     *
     * @return array<int, string>
     */
    private function pendingKeys(): array
    {
        try {
            $members = $this->connection()->smembers(BufferKeys::pendingRegistry());
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to read the pending API usage buffers.', null, $exception);

            return [];
        }

        $keys = is_array($members) ? array_values(array_filter($members, is_string(...))) : [];

        // Minute keys share a prefix and a fixed-width timestamp, so a plain
        // sort is chronological.
        sort($keys);

        return $keys;
    }

    /**
     * Atomically take ownership of a minute buffer and write it to the database.
     *
     * Requests that arrive after the rename land in a freshly created list under
     * the original key, so nothing is lost by claiming the current minute.
     */
    private function claimAndDrain(string $key): int
    {
        $processingKey = $key.':processing:'.Str::uuid();
        $redis = null;
        $unregistered = false;

        try {
            $redis = $this->connection();

            if ((int) $redis->llen($key) === 0) {
                // Expired, or registered by a request that lost a race with an
                // earlier claim: nothing to flush, so drop the registration.
                $redis->srem(BufferKeys::pendingRegistry(), $key);

                return 0;
            }

            /**
             * Unregistered before the rename, never after. A request that
             * lands once the buffer has been renamed creates a fresh list and
             * registers it; unregistering afterwards could remove that new
             * registration and strand the list.
             */
            $redis->srem(BufferKeys::pendingRegistry(), $key);
            $unregistered = true;

            /**
             * Registered before the rename so that a crash between the two
             * still leaves a breadcrumb; recovery tolerates entries that never
             * existed.
             *
             * The member is passed as a plain string. Predis accepts an array
             * here, but phpredis takes members variadically and casts an array
             * argument to the literal "Array" — so the registry filled up with
             * one useless member while every real claimed buffer went
             * unrecorded and could never be recovered.
             */
            $redis->sadd(BufferKeys::processingRegistry(), $processingKey);

            if (! $redis->renamenx($key, $processingKey)) {
                $redis->srem(BufferKeys::processingRegistry(), $processingKey);
                $redis->sadd(BufferKeys::pendingRegistry(), $key);

                return 0;
            }
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to claim buffered API usage events.', $key, $exception);

            if ($unregistered) {
                $this->registerAgain($redis, $key);
            }

            return 0;
        }

        return $this->drain($processingKey);
    }

    /**
     * Re-attempt buffers claimed by an earlier run that never confirmed a write.
     */
    private function recoverAbandonedBuffers(): int
    {
        try {
            $redis = $this->connection();
            $members = $redis->smembers(BufferKeys::processingRegistry());
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to inspect the API usage processing registry.', null, $exception);

            return 0;
        }

        if (! is_array($members)) {
            return 0;
        }

        $recovered = 0;

        foreach ($members as $processingKey) {
            if (! is_string($processingKey)) {
                continue;
            }

            $recovered += $this->drain($processingKey);
        }

        return $recovered;
    }

    /**
     * Read a claimed buffer, insert it, and only then discard it.
     *
     * A short-lived lock keeps a concurrent run — most likely the every-minute
     * schedule overlapping itself — from inserting the same entries twice.
     */
    private function drain(string $processingKey): int
    {
        $redis = null;

        try {
            $redis = $this->connection();

            if (! $this->acquireClaim($redis, $processingKey)) {
                return 0;
            }

            if ((int) $redis->llen($processingKey) === 0) {
                $this->discard($redis, $processingKey);

                return 0;
            }

            $isolate = $this->attempts($redis, $processingKey) >= self::ISOLATE_AFTER_ATTEMPTS;

            // One transaction for the whole buffer: a failure part-way rolls
            // everything back, so the retry cannot insert the earlier chunks a
            // second time.
            $written = (new ApiUsageRequest)->getConnection()->transaction(
                fn (): int => $isolate
                    ? $this->insertIsolating($redis, $processingKey)
                    : $this->insertBuffered($redis, $processingKey)
            );

            $this->discard($redis, $processingKey);

            return $written;
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to flush buffered API usage events.', $processingKey, $exception);

            // The key stays in the registry and the lock is left to expire, so
            // the next scheduled run retries instead of dropping the entries.
            $this->keepForRetry($redis, $processingKey);
            $this->countAttempt($redis, $processingKey);

            return 0;
        }
    }

    /**
     * Read a claimed buffer one batch at a time and insert each batch, so a
     * busy minute is never held in memory as a whole.
     *
     * @return int Rows written
     */
    private function insertBuffered(Connection $redis, string $processingKey): int
    {
        $size = UsageConfig::flushBatchSize();
        $offset = 0;
        $written = 0;

        do {
            $entries = $redis->lrange($processingKey, $offset, $offset + $size - 1);
            $rows = $this->rowsFrom($entries);

            if ($rows !== []) {
                ApiUsageRequest::query()->insert($rows);
                $written += count($rows);
            }

            $offset += $size;
        } while (is_array($entries) && count($entries) === $size);

        return $written;
    }

    /**
     * Put a buffer back on the pending list after a claim that did not happen,
     * so the next run sees it. Best effort: a Redis that is failing here is
     * already being reported.
     */
    private function registerAgain(?Connection $redis, string $key): void
    {
        try {
            $redis?->sadd(BufferKeys::pendingRegistry(), $key);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * The same read as insertBuffered(), but each row is inserted on its own
     * inside a savepoint, so a row the database refuses is rolled back alone.
     *
     * Only a refusal of the row itself counts (see isRowRejection()). Anything
     * else — a lost connection, a deadlock, a missing table — aborts the whole
     * run, which rolls back and leaves the buffer for the next one: good events
     * must never be written off because the database had a bad moment.
     *
     * @return int Rows written
     */
    private function insertIsolating(Connection $redis, string $processingKey): int
    {
        $database = (new ApiUsageRequest)->getConnection();
        $size = UsageConfig::flushBatchSize();
        $offset = 0;
        $written = 0;

        /** @var array<int, array{0: string, 1: string}> $rejected */
        $rejected = [];

        do {
            $entries = $redis->lrange($processingKey, $offset, $offset + $size - 1);

            foreach ($this->decode($entries) as [$raw, $row]) {
                try {
                    // Nested inside the run's transaction, so this is a savepoint.
                    $database->transaction(fn () => ApiUsageRequest::query()->insert($row));
                    $written++;
                } catch (\Throwable $exception) {
                    if (! $this->isRowRejection($exception)) {
                        throw $exception;
                    }

                    $rejected[] = [$raw, $exception->getMessage()];
                }
            }

            $offset += $size;
        } while (is_array($entries) && count($entries) === $size);

        if ($rejected !== []) {
            // Stored before the transaction commits: if Redis fails, the run
            // rolls back and retries; if the commit fails, the retry at worst
            // lists the same rejects twice.
            $this->keepRejected($redis, $rejected);
        }

        return $written;
    }

    /**
     * Whether the database refused this row for what it contains: a constraint
     * or a data error. Any other failure says nothing about the row.
     */
    private function isRowRejection(\Throwable $exception): bool
    {
        if (! $exception instanceof QueryException) {
            return false;
        }

        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return str_starts_with($sqlState, '22')
            || str_starts_with($sqlState, '23')
            || in_array($driverCode, self::MYSQL_DATA_ERROR_CODES, true);
    }

    /**
     * @param  array<int, array{0: string, 1: string}>  $rejected  Raw entry and the database's reason
     */
    private function keepRejected(Connection $redis, array $rejected): void
    {
        $list = BufferKeys::rejected();
        $now = now()->utc()->toDateTimeString();

        foreach ($rejected as [$raw, $reason]) {
            $redis->rpush($list, (string) json_encode([
                'rejected_at' => $now,
                'reason' => $reason,
                'entry' => $raw,
            ]));
        }

        $redis->ltrim($list, -self::REJECTED_LIMIT, -1);
        $redis->expire($list, self::REJECTED_TTL_SECONDS);

        Log::warning('The database rejected buffered API usage events; they were moved to the rejected list.', [
            'count' => count($rejected),
            'key' => $list,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(mixed $entries): array
    {
        return array_map(fn (array $pair): array => $pair[1], $this->decode($entries));
    }

    /**
     * Decode buffered entries into insertable rows, keeping each entry's raw
     * text so a rejected one can be stored exactly as it was buffered. Entries
     * that are not valid, or come from an unknown payload version, are skipped.
     *
     * @return array<int, array{0: string, 1: array<string, mixed>}>
     */
    private function decode(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $decoded = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $payload = json_decode($entry, true);

            if (! is_array($payload)) {
                continue;
            }

            $row = UsageRecorder::prepareForInsert($payload);

            if ($row !== null) {
                $decoded[] = [$entry, $row];
            }
        }

        return $decoded;
    }

    private function attempts(Connection $redis, string $processingKey): int
    {
        return (int) $redis->get(BufferKeys::attemptsFor($processingKey));
    }

    private function countAttempt(?Connection $redis, string $processingKey): void
    {
        if ($redis === null) {
            return;
        }

        try {
            $key = BufferKeys::attemptsFor($processingKey);

            $redis->incr($key);
            $redis->expire($key, UsageConfig::redisTtlSeconds());
        } catch (\Throwable) {
            //
        }
    }

    private function acquireClaim(Connection $redis, string $processingKey): bool
    {
        $lockKey = BufferKeys::lockFor($processingKey);

        /**
         * SET key 1 EX <ttl> NX — a single atomic call, so a crash can never
         * leave a lock behind that has no expiry.
         *
         * The two clients disagree about how to spell that, and the difference
         * is not cosmetic: phpredis's set() wants the options as an array and
         * takes at most three arguments, while predis takes them positionally.
         * Sending the positional form through command(), which forwards
         * verbatim to the underlying client, raised "Redis::set() expects at
         * most 3 arguments, 5 given" on every claim — so on the client this
         * package recommends, nothing was ever flushed.
         *
         * PhpRedisConnection::set() exists to translate between the two, so it
         * is called directly rather than reached through __call. Predis has no
         * such override and takes the positional form as written.
         */
        if ($redis instanceof PhpRedisConnection) {
            return (bool) $redis->set($lockKey, '1', 'EX', self::CLAIM_TTL_SECONDS, 'NX');
        }

        return (bool) $redis->command('set', [$lockKey, '1', 'EX', self::CLAIM_TTL_SECONDS, 'NX']);
    }

    private function discard(Connection $redis, string $processingKey): void
    {
        $redis->del($processingKey);
        $redis->del(BufferKeys::lockFor($processingKey));
        $redis->del(BufferKeys::attemptsFor($processingKey));
        $redis->srem(BufferKeys::processingRegistry(), $processingKey);
    }

    private function keepForRetry(?Connection $redis, string $processingKey): void
    {
        if ($redis === null) {
            return;
        }

        try {
            // renamenx carries over the original TTL, which may be close to
            // expiry; extend it so the retry window is not lost.
            $redis->expire($processingKey, UsageConfig::redisTtlSeconds());
        } catch (\Throwable) {
            //
        }
    }

    private function connection(): Connection
    {
        return $this->connection ??= Redis::connection(UsageConfig::redisConnection());
    }

    private function reportFailure(string $message, ?string $key, \Throwable $exception): void
    {
        Log::error($message, array_filter([
            'key' => $key,
            'exception' => $exception::class,
            'error' => $exception->getMessage(),
        ]));
    }
}
