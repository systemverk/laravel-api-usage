<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;
use Systemverk\LaravelApiUsage\Tests\Support\FakeRedisConnection;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class RecordApiUsageMiddlewareTest extends TestCase
{
    public function test_handle_returns_the_response_untouched_and_defers_the_write(): void
    {
        $redis = $this->fakeRedis();
        $request = Request::create('/api/orders');
        $response = new Response('ok', 200);

        $returned = $this->middleware()->handle($request, fn () => $response);

        $this->assertSame($response, $returned);
        $this->assertSame([], $redis->store, 'handle() must not touch Redis.');
        $this->assertIsFloat($request->attributes->get(RecordApiUsage::STARTED_AT));
    }

    public function test_terminate_buffers_the_event_and_sets_a_ttl(): void
    {
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response('ok', 201));

        $key = BufferKeys::currentMinute();

        $this->assertArrayHasKey($key, $redis->store);
        $this->assertSame(7200, $redis->ttls[$key]);

        $entry = json_decode($redis->store[$key][0], true);

        $this->assertSame('/api/orders', $entry['path']);
        $this->assertSame('GET:/api/orders', $entry['endpoint_key']);
        $this->assertSame('guest', $entry['actor_key']);
        $this->assertSame(201, $entry['status_code']);
    }

    public function test_terminate_buffers_the_resolved_credential(): void
    {
        $redis = $this->fakeRedis();

        // Stands in for $request->user()->currentAccessToken()->getKey().
        UsageRecorder::resolveCredentialUsing(fn (Request $request) => $request->headers->get('X-Token-Id'));

        $request = Request::create('/api/orders');
        $request->headers->set('X-Token-Id', '91');

        $this->runMiddleware($request, new Response('ok', 200));

        $entry = json_decode($redis->store[BufferKeys::currentMinute()][0], true);

        $this->assertSame('91', $entry['credential_id']);
    }

    public function test_terminate_records_nothing_when_the_actor_resolver_opts_out(): void
    {
        config()->set('api_usage.actor.track_guests', false);
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_terminate_records_nothing_when_disabled(): void
    {
        config()->set('api_usage.enabled', false);
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_terminate_respects_excluded_paths(): void
    {
        config()->set('api_usage.except', ['up']);
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/up'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_terminate_is_a_no_op_when_the_redis_connection_is_not_configured(): void
    {
        config()->set('api_usage.buffer.connection', 'nonexistent');
        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame([], $redis->store);
    }

    public function test_a_cluster_connection_is_recognised(): void
    {
        config()->set('api_usage.buffer.connection', 'usage');
        config()->set('database.redis.clusters.usage', [['host' => '127.0.0.1', 'port' => 6379]]);

        $redis = $this->fakeRedis();

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertNotSame([], $redis->store);
    }

    public function test_a_redis_failure_is_logged_and_swallowed(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message, array $context) => str_contains($message, 'Failed to buffer')
                && $context['error'] === 'connection refused');

        $redis = new FakeRedisConnection;
        $redis->failOn('rpush', new \RuntimeException('connection refused'));
        Redis::shouldReceive('connection')->andReturn($redis);

        $this->runMiddleware(Request::create('/api/orders'), new Response);
    }

    public function test_invalid_utf8_from_a_resolver_cannot_corrupt_the_buffer(): void
    {
        $redis = $this->fakeRedis();

        // A resolver is the only route by which raw bytes could reach the
        // payload — everything read from the request has already been
        // sanitized by Symfony. Either the recorder's own truncation cleans it
        // up, or json_encode throws and the entry is dropped; what must never
        // happen is an unreadable line landing in the buffer.
        UsageRecorder::resolveCredentialUsing(fn () => "bad\xB1\x31utf8");

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        foreach ($redis->store as $entries) {
            foreach ((array) $entries as $entry) {
                $this->assertIsArray(json_decode((string) $entry, true), 'Buffered entries must be valid JSON.');
            }
        }
    }

    public function test_the_middleware_is_appended_to_the_api_group_by_default(): void
    {
        $this->assertContains(
            RecordApiUsage::class,
            $this->app()->make(\Illuminate\Foundation\Http\Kernel::class)->getMiddlewareGroups()['api'] ?? []
        );
    }

    private function middleware(): RecordApiUsage
    {
        return $this->app()->make(RecordApiUsage::class);
    }

    private function runMiddleware(Request $request, Response $response): void
    {
        $middleware = $this->middleware();
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }
}
