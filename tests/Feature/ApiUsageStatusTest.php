<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Tests\Support\FakeRedisConnection;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class ApiUsageStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_the_current_configuration(): void
    {
        $this->fakeRedis();

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('Enabled')
            ->expectsOutputToContain('Sampling rate')
            ->expectsOutputToContain('Raw retention')
            ->assertExitCode(0);
    }

    public function test_it_reports_the_pending_buffer_depth(): void
    {
        $redis = $this->fakeRedis();
        $redis->rpush(BufferKeys::currentMinute(), 'a', 'b', 'c');
        $redis->sadd(BufferKeys::pendingRegistry(), BufferKeys::currentMinute());
        $redis->rpush(BufferKeys::forMinute(now()->utc()->subHours(2)), 'd');
        $redis->sadd(BufferKeys::pendingRegistry(), BufferKeys::forMinute(now()->utc()->subHours(2)));

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('4 events in 2 buffers')
            ->assertExitCode(0);
    }

    public function test_it_reports_claimed_buffers_awaiting_recovery(): void
    {
        $redis = $this->fakeRedis();
        $redis->sadd(BufferKeys::processingRegistry(), 'some-claimed-key');

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('1 claimed buffers')
            ->assertExitCode(0);
    }

    public function test_it_reports_events_the_database_rejected(): void
    {
        $redis = $this->fakeRedis();
        $redis->rpush(BufferKeys::rejected(), 'a', 'b');

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('2 events')
            ->assertExitCode(0);
    }

    public function test_it_survives_an_unreachable_redis(): void
    {
        $redis = new FakeRedisConnection;
        $redis->failOn('smembers', new \RuntimeException('connection refused'));
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('unreachable')
            ->assertExitCode(0);
    }

    public function test_it_counts_the_stored_rows(): void
    {
        $this->fakeRedis();

        ApiUsageRequest::query()->create([
            'requested_at' => now()->utc(),
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'method' => 'GET',
            'path' => '/api/orders',
            'endpoint_key' => 'GET:/api/orders',
            'status_code' => 200,
            'duration_ms' => 5,
        ]);

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('Raw rows')
            ->assertExitCode(0);
    }

    public function test_it_never_mutates_usage_state(): void
    {
        $redis = $this->fakeRedis();
        $key = BufferKeys::currentMinute();
        $redis->rpush($key, 'a', 'b');

        $this->artisan('api-usage:status')->assertExitCode(0);

        $this->assertCount(2, $redis->store[$key]);
        $this->assertSame(0, ApiUsageRequest::query()->count());
    }
}
