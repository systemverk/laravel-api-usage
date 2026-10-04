<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Tests\Support\FakeRedisConnection;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class FlushApiUsageTest extends TestCase
{
    use RefreshDatabase;

    private FakeRedisConnection $redis;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));
        $this->redis = $this->fakeRedis();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_writes_buffered_events_to_the_database(): void
    {
        $this->buffer(BufferKeys::currentMinute(), [
            $this->entry(['path' => '/api/orders', 'status_code' => 200]),
            $this->entry(['path' => '/api/orders/1', 'status_code' => 404]),
        ]);

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('Flushed 2 API usage events.')
            ->assertExitCode(0);

        $this->assertSame(2, ApiUsageRequest::query()->count());
        $this->assertSame('/api/orders', ApiUsageRequest::query()->orderBy('id')->first()?->path);
    }

    public function test_it_persists_the_full_actor_endpoint_and_credential_identity(): void
    {
        $this->buffer(BufferKeys::currentMinute(), [
            $this->entry([
                'actor_type' => 'organization',
                'actor_id' => '42',
                'credential_id' => '91',
                'route_name' => 'api.orders.show',
                'route_uri' => '/api/orders/{order}',
                'path' => '/api/orders/7',
                'endpoint_key' => 'GET:api.orders.show',
            ]),
        ]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $row = ApiUsageRequest::query()->firstOrFail();

        $this->assertSame('organization', $row->actor_type);
        $this->assertSame('42', $row->actor_id);
        $this->assertSame('91', $row->credential_id);
        $this->assertSame('GET:api.orders.show', $row->endpoint_key);
        $this->assertSame('api.orders.show', $row->route_name);
    }

    /**
     * An outage must not strand data: a buffer is flushed no matter how many
     * minutes ago it was written, because the registry names it.
     */
    public function test_it_flushes_buffers_from_any_minute_however_old(): void
    {
        $this->buffer(BufferKeys::forMinute(Carbon::now('UTC')->subMinutes(3)), [$this->entry()]);
        $this->buffer(BufferKeys::forMinute(Carbon::now('UTC')->subHours(1)), [$this->entry()]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(2, ApiUsageRequest::query()->count());
    }

    public function test_it_ignores_buffers_that_were_never_registered(): void
    {
        $this->redis->rpush(BufferKeys::currentMinute(), $this->entry());

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
    }

    public function test_it_removes_the_buffer_after_a_successful_write(): void
    {
        $key = BufferKeys::currentMinute();
        $this->buffer($key, [$this->entry()]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertArrayNotHasKey($key, $this->redis->store);
        $this->assertSame([], $this->redis->smembers(BufferKeys::processingRegistry()));
        $this->assertSame([], $this->redis->smembers(BufferKeys::pendingRegistry()));
    }

    public function test_a_registration_whose_buffer_has_expired_is_dropped(): void
    {
        $this->redis->sadd(BufferKeys::pendingRegistry(), BufferKeys::currentMinute());

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame([], $this->redis->smembers(BufferKeys::pendingRegistry()));
    }

    /**
     * A buffer that could not be claimed has to stay visible to the next run.
     */
    public function test_a_failed_claim_leaves_the_buffer_registered(): void
    {
        Log::shouldReceive('error')->once();

        $key = BufferKeys::currentMinute();
        $this->buffer($key, [$this->entry()]);
        $this->redis->failOn('renamenx', new \RuntimeException('connection reset'));

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame([$key], $this->redis->smembers(BufferKeys::pendingRegistry()));
        $this->assertArrayHasKey($key, $this->redis->store);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
    }

    /**
     * Chunks are inserted inside one transaction. Without it, a failure on the
     * second chunk keeps the first one, and the retry inserts it again.
     */
    public function test_a_failure_part_way_through_a_buffer_writes_nothing_and_retries_cleanly(): void
    {
        Log::shouldReceive('error')->once();
        config()->set('api_usage.buffer.flush_batch_size', 2);

        $this->buffer(BufferKeys::currentMinute(), [
            $this->entry(), $this->entry(), $this->entry(), $this->entry(), $this->entry(),
        ]);

        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'insert') && ++$inserts === 2) {
                throw new \RuntimeException('deadlock found');
            }
        });

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count(), 'The first chunk must be rolled back.');

        $registry = $this->redis->smembers(BufferKeys::processingRegistry());
        $this->redis->del(BufferKeys::lockFor($registry[0]));

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(5, ApiUsageRequest::query()->count());
    }

    public function test_a_buffer_that_expires_mid_flush_does_not_crash_the_command(): void
    {
        // Reproduces the predis "ERR no such key" race: llen sees entries, the
        // key expires, and renamenx then fails.
        $this->buffer(BufferKeys::currentMinute(), [$this->entry()]);

        Log::shouldReceive('error')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to claim'));

        $this->redis->failOn('renamenx', new \RuntimeException('ERR no such key'));

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('Flushed 0 API usage events.')
            ->assertExitCode(0);
    }

    public function test_malformed_entries_are_skipped_without_losing_the_rest(): void
    {
        $this->buffer(BufferKeys::currentMinute(), [
            'not json at all',
            (string) json_encode(['method' => 'GET']),
            $this->entry(['path' => '/api/good']),
        ]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
        $this->assertSame('/api/good', ApiUsageRequest::query()->first()?->path);
    }

    public function test_an_entry_from_an_unknown_payload_version_is_skipped(): void
    {
        // A rolling deploy can leave a newer worker's payloads in a buffer an
        // older one drains. Skipping beats half-decoding them.
        $this->buffer(BufferKeys::currentMinute(), [
            $this->entry(['v' => 99, 'path' => '/api/from-the-future']),
            $this->entry(['path' => '/api/current']),
        ]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
        $this->assertSame('/api/current', ApiUsageRequest::query()->first()?->path);
    }

    public function test_a_buffer_containing_only_junk_is_discarded(): void
    {
        $key = BufferKeys::currentMinute();
        $this->buffer($key, ['garbage']);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
        $this->assertArrayNotHasKey($key, $this->redis->store);
        $this->assertSame([], $this->redis->smembers(BufferKeys::processingRegistry()));
    }

    public function test_a_failed_write_is_retried_on_the_next_run_instead_of_being_dropped(): void
    {
        Log::shouldReceive('error')->once();

        $this->buffer(BufferKeys::currentMinute(), [$this->entry(), $this->entry()]);

        $this->redis->failOn('lrange', new \RuntimeException('read timeout'));

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());

        $registry = $this->redis->smembers(BufferKeys::processingRegistry());
        $this->assertCount(1, $registry, 'The claimed buffer must stay registered for recovery.');

        // The claim lock blocks an immediate retry; release it as its TTL would.
        $this->redis->del(BufferKeys::lockFor($registry[0]));

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('2 recovered from a previous run')
            ->assertExitCode(0);

        $this->assertSame(2, ApiUsageRequest::query()->count());
        $this->assertSame([], $this->redis->smembers(BufferKeys::processingRegistry()));
    }

    public function test_a_claimed_buffer_is_not_processed_twice_while_locked(): void
    {
        $this->buffer(BufferKeys::currentMinute(), [$this->entry()]);

        $this->artisan('api-usage:flush')->assertExitCode(0);
        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
    }

    public function test_an_expired_orphan_is_cleaned_out_of_the_registry(): void
    {
        $orphan = BufferKeys::currentMinute().':processing:gone';
        $this->redis->sadd(BufferKeys::processingRegistry(), $orphan);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame([], $this->redis->smembers(BufferKeys::processingRegistry()));
    }

    /**
     * The registry has to hold the key of the buffer that was claimed.
     *
     * Passing the member as a single-element array reads fine and works on
     * predis, but phpredis casts it to the literal string "Array": the registry
     * then holds one member that resolves to nothing, every genuinely claimed
     * buffer goes unrecorded, and recovery can never find them again.
     */
    public function test_the_processing_registry_holds_the_claimed_buffer_key(): void
    {
        Log::shouldReceive('error')->once();

        $this->buffer(BufferKeys::currentMinute(), [$this->entry()]);
        $this->redis->failOn('lrange', new \RuntimeException('read timeout'));

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $registry = $this->redis->smembers(BufferKeys::processingRegistry());

        $this->assertCount(1, $registry);
        $this->assertNotSame('Array', $registry[0]);
        $this->assertStringStartsWith(BufferKeys::currentMinute().':processing:', $registry[0]);
    }

    /**
     * The claim is a SET NX carrying an expiry, so a run that dies mid-flush
     * cannot leave a lock behind that never releases.
     */
    public function test_the_claim_lock_is_written_with_an_expiry(): void
    {
        Log::shouldReceive('error')->once();

        $this->buffer(BufferKeys::currentMinute(), [$this->entry()]);
        $this->redis->failOn('lrange', new \RuntimeException('read timeout'));

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $registry = $this->redis->smembers(BufferKeys::processingRegistry());
        $lockKey = BufferKeys::lockFor($registry[0]);

        $this->assertArrayHasKey($lockKey, $this->redis->store);
        $this->assertSame(300, $this->redis->ttls[$lockKey] ?? null);
    }

    /**
     * One row the database will never accept must not hold a whole minute of
     * good events hostage until Redis expires it.
     *
     * The first failures are retried as they are, since most are transient.
     * Once a buffer has failed three times, the next run inserts row by row,
     * writes what the database takes and moves the rest to the rejected list.
     */
    public function test_rows_the_database_rejects_are_isolated_after_repeated_failures(): void
    {
        Log::shouldReceive('error')->times(3);
        Log::shouldReceive('warning')->once();

        $this->databaseRefusesRowsWithPath('/api/poison');

        $poison = $this->entry(['path' => '/api/poison']);

        $this->buffer(BufferKeys::currentMinute(), [
            $this->entry(['path' => '/api/first']),
            $poison,
            $this->entry(['path' => '/api/second']),
        ]);

        $this->failFlushes(3);

        $this->assertSame(0, ApiUsageRequest::query()->count(), 'Failed runs must write nothing.');

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('2 recovered from a previous run')
            ->assertExitCode(0);

        $this->assertSame(['/api/first', '/api/second'], ApiUsageRequest::query()->orderBy('id')->pluck('path')->all());

        $rejected = $this->redis->lrange(BufferKeys::rejected(), 0, -1);

        $this->assertCount(1, $rejected);
        $this->assertSame($poison, json_decode($rejected[0], true)['entry']);
        $this->assertNotEmpty(json_decode($rejected[0], true)['reason']);

        $this->assertSame([], $this->redis->smembers(BufferKeys::processingRegistry()));
        $this->assertSame([], array_filter(
            array_keys($this->redis->store),
            fn (string $key) => str_ends_with($key, ':attempts')
        ));
    }

    /**
     * Isolation is only for rows the database refuses on their own merits. If
     * the database is simply failing — down, deadlocked, missing a table —
     * every row fails, and none of them is bad.
     */
    public function test_a_failing_database_never_causes_good_events_to_be_rejected(): void
    {
        Log::shouldReceive('error')->times(4);

        $this->buffer(BufferKeys::currentMinute(), [$this->entry(), $this->entry()]);

        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'insert')) {
                throw new \RuntimeException('server has gone away');
            }
        });

        $this->failFlushes(4);

        $this->assertSame(0, ApiUsageRequest::query()->count());
        $this->assertSame([], $this->redis->lrange(BufferKeys::rejected(), 0, -1));
        $this->assertCount(1, $this->redis->smembers(BufferKeys::processingRegistry()), 'The buffer must be kept.');
    }

    public function test_the_rejected_list_is_capped(): void
    {
        Log::shouldReceive('error')->times(3);
        Log::shouldReceive('warning')->once();

        $this->databaseRefusesRowsWithPath('/api/poison');

        $entries = [$this->entry(['path' => '/api/good'])];

        for ($i = 0; $i < 1200; $i++) {
            $entries[] = $this->entry(['path' => '/api/poison']);
        }

        $this->buffer(BufferKeys::currentMinute(), $entries);

        $this->failFlushes(3);
        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
        $this->assertCount(1000, $this->redis->lrange(BufferKeys::rejected(), 0, -1));
        $this->assertSame(604800, $this->redis->ttls[BufferKeys::rejected()] ?? null);
    }

    /**
     * Make the database refuse any insert that carries this path, the way a
     * constraint or a data error would: a QueryException with an integrity
     * SQLSTATE, raised before the statement runs.
     *
     * Done here rather than with a real constraint because changing the schema
     * inside a test commits the transaction the test runs in on MySQL, which
     * then no longer matches what Laravel believes about its savepoints.
     */
    private function databaseRefusesRowsWithPath(string $path): void
    {
        DB::beforeExecuting(function (string $query, array $bindings) use ($path): void {
            if (! str_starts_with(strtolower(trim($query)), 'insert') || ! in_array($path, $bindings, true)) {
                return;
            }

            $refusal = new \PDOException('Duplicate entry');
            $refusal->errorInfo = ['23000', 1062, 'Duplicate entry'];

            throw new QueryException('testing', $query, $bindings, $refusal);
        });
    }

    /**
     * Run the flush command until it has failed the given number of times,
     * releasing the claim lock in between as its TTL would.
     */
    private function failFlushes(int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->artisan('api-usage:flush')->assertExitCode(0);

            foreach ($this->redis->smembers(BufferKeys::processingRegistry()) as $key) {
                $this->redis->del(BufferKeys::lockFor($key));
            }
        }
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_usage.enabled', false);

        $this->buffer(BufferKeys::currentMinute(), [$this->entry()]);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
    }

    public function test_it_inserts_in_batches_of_the_configured_size(): void
    {
        config()->set('api_usage.buffer.flush_batch_size', 2);

        $entries = [];

        for ($i = 0; $i < 5; $i++) {
            $entries[] = $this->entry(['path' => "/api/orders/{$i}"]);
        }

        $this->buffer(BufferKeys::currentMinute(), $entries);

        $inserts = 0;
        DB::listen(function (QueryExecuted $query) use (&$inserts): void {
            if (str_starts_with(strtolower(trim($query->sql)), 'insert')) {
                $inserts++;
            }
        });

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(5, ApiUsageRequest::query()->count());
        $this->assertSame(3, $inserts, 'Five rows at a batch size of two should take three inserts.');
    }

    /**
     * @param  array<int, string>  $entries
     */
    private function buffer(string $key, array $entries): void
    {
        $this->redis->rpush($key, ...$entries);
        $this->redis->sadd(BufferKeys::pendingRegistry(), $key);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function entry(array $overrides = []): string
    {
        return (string) json_encode(array_merge([
            'v' => UsageEvent::VERSION,
            'requested_at' => Carbon::now('UTC')->toDateTimeString(),
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'credential_id' => null,
            'method' => 'GET',
            'route_name' => null,
            'route_uri' => null,
            'path' => '/api/orders',
            'endpoint_key' => 'GET:/api/orders',
            'status_code' => 200,
            'duration_ms' => 12,
            'ip_hash' => null,
            'user_agent' => null,
            'request_id' => null,
        ], $overrides));
    }
}
