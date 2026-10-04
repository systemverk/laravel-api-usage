<?php

namespace Systemverk\LaravelApiUsage\Tests\Integration;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Tests\TestCase;

/**
 * The flush command and the middleware against a real Redis, through a real
 * client.
 *
 * The rest of the suite uses an in-memory double, and its own comments admit
 * what that cannot prove: phpredis and predis disagree about how SET takes its
 * options and how SADD takes its members, and both of those once broke flushing
 * outright while every test stayed green. These tests are the ones that would
 * have failed.
 *
 * They are skipped unless TEST_REDIS_PORT points at a Redis (TEST_REDIS_HOST
 * defaults to 127.0.0.1). One concrete class per client, so each runs against
 * exactly one of them.
 */
abstract class RedisIntegrationTestCase extends TestCase
{
    use RefreshDatabase;

    private string $prefix;

    private bool $refuseInserts = false;

    /**
     * @return 'phpredis'|'predis'
     */
    abstract protected function client(): string;

    protected function setUp(): void
    {
        if (! getenv('TEST_REDIS_PORT')) {
            $this->markTestSkipped('Set TEST_REDIS_PORT to run the Redis integration tests.');
        }

        if ($this->client() === 'phpredis' && ! extension_loaded('redis')) {
            $this->markTestSkipped('ext-redis is not installed.');
        }

        if ($this->client() === 'predis' && ! class_exists(\Predis\Client::class)) {
            $this->markTestSkipped('predis/predis is not installed.');
        }

        parent::setUp();

        $this->prefix = 'api_usage_it_'.bin2hex(random_bytes(4)).':';
        config()->set('api_usage.buffer.key_prefix', $this->prefix);
    }

    protected function tearDown(): void
    {
        if (isset($this->prefix)) {
            $redis = Redis::connection();

            foreach ($redis->keys($this->prefix.'*') as $key) {
                $redis->del($key);
            }
        }

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.client', $this->client());
        $app['config']->set('database.redis.default', [
            'host' => getenv('TEST_REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('TEST_REDIS_PORT') ?: 6379),
            'database' => 0,
        ]);
    }

    public function test_a_request_is_buffered_and_flushed_end_to_end(): void
    {
        $this->record('/api/orders/1');
        $this->record('/api/orders/2');

        $redis = Redis::connection();
        $key = BufferKeys::currentMinute();

        $this->assertSame([$key], $redis->smembers(BufferKeys::pendingRegistry()));
        $this->assertSame(2, (int) $redis->llen($key));
        $this->assertGreaterThan(0, (int) $redis->ttl($key), 'The buffer needs an expiry.');

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('Flushed 2 API usage events.')
            ->assertExitCode(0);

        $this->assertSame(2, ApiUsageRequest::query()->count());
        $this->assertSame(0, (int) $redis->exists($key));
        $this->assertSame([], $redis->smembers(BufferKeys::pendingRegistry()));
        $this->assertSame([], $redis->smembers(BufferKeys::processingRegistry()));
        $this->assertSame([], $redis->keys($this->prefix.'*:lock'), 'The claim lock must be released.');
    }

    /**
     * The claim lock is SET NX EX. Sent the wrong way for the client in use,
     * this raised on every claim, and nothing was ever flushed.
     */
    public function test_the_claim_lock_is_taken_with_an_expiry(): void
    {
        $this->record('/api/orders');
        $this->databaseRefusesInserts(true);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $redis = Redis::connection();
        $claimed = $redis->smembers(BufferKeys::processingRegistry());

        $this->assertCount(1, $claimed);

        $ttl = (int) $redis->ttl(BufferKeys::lockFor($claimed[0]));

        $this->assertGreaterThan(0, $ttl);
        $this->assertLessThanOrEqual(300, $ttl);
    }

    /**
     * SADD takes its members one per argument. An array reads fine to predis
     * and is cast to the string "Array" by phpredis, which left the registry
     * pointing at nothing and every claimed buffer unrecoverable.
     */
    public function test_the_registries_hold_real_buffer_keys(): void
    {
        $this->record('/api/orders');

        $redis = Redis::connection();

        $this->assertSame([BufferKeys::currentMinute()], $redis->smembers(BufferKeys::pendingRegistry()));

        $this->databaseRefusesInserts(true);
        $this->artisan('api-usage:flush')->assertExitCode(0);

        $claimed = $redis->smembers(BufferKeys::processingRegistry());

        $this->assertCount(1, $claimed);
        $this->assertStringStartsWith(BufferKeys::currentMinute().':processing:', $claimed[0]);
        $this->assertSame(1, (int) $redis->llen($claimed[0]), 'The registry must name the claimed buffer.');
    }

    public function test_a_failed_flush_is_recovered_by_the_next_run(): void
    {
        $this->record('/api/orders');
        $this->databaseRefusesInserts(true);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());

        $redis = Redis::connection();
        $claimed = $redis->smembers(BufferKeys::processingRegistry());

        $this->assertSame(1, (int) $redis->get(BufferKeys::attemptsFor($claimed[0])), 'The failure must be counted.');

        // The lock would expire on its own after five minutes.
        $redis->del(BufferKeys::lockFor($claimed[0]));
        $this->databaseRefusesInserts(false);

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('1 recovered from a previous run')
            ->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
        $this->assertSame([], $redis->keys($this->prefix.'*:attempts'));
    }

    public function test_events_the_database_refuses_end_up_on_the_rejected_list(): void
    {
        $this->record('/api/good');
        $this->record('/api/poison');
        $this->record('/api/also-good');

        $this->databaseRefusesRowsWithPath('/api/poison');

        $redis = Redis::connection();

        for ($run = 0; $run < 3; $run++) {
            $this->artisan('api-usage:flush')->assertExitCode(0);

            foreach ($redis->smembers(BufferKeys::processingRegistry()) as $claimed) {
                $redis->del(BufferKeys::lockFor($claimed));
            }
        }

        $this->assertSame(0, ApiUsageRequest::query()->count());

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(['/api/good', '/api/also-good'], ApiUsageRequest::query()->orderBy('id')->pluck('path')->all());

        $rejected = $redis->lrange(BufferKeys::rejected(), 0, -1);

        $this->assertCount(1, $rejected);
        $this->assertSame('/api/poison', json_decode(json_decode($rejected[0], true)['entry'], true)['path']);
        $this->assertGreaterThan(0, (int) $redis->ttl(BufferKeys::rejected()));
        $this->assertSame([], $redis->smembers(BufferKeys::processingRegistry()));
    }

    public function test_a_buffer_older_than_any_window_is_still_flushed(): void
    {
        $redis = Redis::connection();
        $hourAgo = now()->utc()->subHour();
        $old = BufferKeys::forMinute($hourAgo);

        $entry = [
            'v' => UsageEvent::VERSION,
            'requested_at' => $hourAgo->toDateTimeString(),
            'actor_type' => 'guest', 'actor_id' => 'guest', 'credential_id' => null,
            'method' => 'GET', 'route_name' => null, 'route_uri' => null,
            'path' => '/api/old', 'endpoint_key' => 'GET:/api/old',
            'status_code' => 200, 'duration_ms' => 5,
            'ip_hash' => null, 'user_agent' => null, 'request_id' => null,
        ];

        $redis->rpush($old, (string) json_encode($entry));
        $redis->sadd(BufferKeys::pendingRegistry(), $old);

        $this->artisan('api-usage:flush')->assertExitCode(0);

        $this->assertSame(['/api/old'], ApiUsageRequest::query()->pluck('path')->all());
    }

    public function test_the_status_command_reads_a_real_redis(): void
    {
        $this->record('/api/orders');

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('1 events in 1 buffers')
            ->assertExitCode(0);
    }

    private function record(string $path): void
    {
        $request = Request::create($path);
        $response = new Response('ok', 200);

        $middleware = $this->app()->make(RecordApiUsage::class);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }

    /**
     * A database that is simply failing: nothing a flush wrote would stick.
     * Can be switched off again, since a registered callback cannot be removed.
     */
    private function databaseRefusesInserts(bool $refuse): void
    {
        $firstTime = ! $this->refuseInserts && $refuse;
        $this->refuseInserts = $refuse;

        if (! $firstTime) {
            return;
        }

        DB::beforeExecuting(function (string $query): void {
            if ($this->refuseInserts && str_starts_with(strtolower(trim($query)), 'insert')) {
                throw new \RuntimeException('server has gone away');
            }
        });
    }

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
}
