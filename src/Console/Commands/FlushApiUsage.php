<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Illuminate\Console\Command;
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

            // One transaction for the whole buffer: a failure part-way rolls
            // everything back, so the retry cannot insert the earlier chunks a
            // second time.
            $written = (new ApiUsageRequest)->getConnection()->transaction(
                fn (): int => $this->insertBuffered($redis, $processingKey)
            );

            $this->discard($redis, $processingKey);

            return $written;
        } catch (\Throwable $exception) {
            $this->reportFailure('Failed to flush buffered API usage events.', $processingKey, $exception);

            // The key stays in the registry and the lock is left to expire, so
            // the next scheduled run retries instead of dropping the entries.
            $this->keepForRetry($redis, $processingKey);

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
     * @return array<int, array<string, mixed>>
     */
    private function rowsFrom(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $rows = [];

        foreach ($entries as $entry) {
            if (! is_string($entry)) {
                continue;
            }

            $decoded = json_decode($entry, true);

            if (! is_array($decoded)) {
                continue;
            }

            $row = UsageRecorder::prepareForInsert($decoded);

            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
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
