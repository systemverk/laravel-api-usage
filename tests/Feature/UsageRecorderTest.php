<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class UsageRecorderTest extends TestCase
{
    private UsageRecorder $recorder;

    protected function setUp(): void
    {
        parent::setUp();

        $this->recorder = new UsageRecorder;
    }

    // -----------------------------------------------------------------
    // Buffer keys
    // -----------------------------------------------------------------

    public function test_it_builds_the_minute_key_from_the_configured_prefix(): void
    {
        config()->set('api_usage.buffer.key_prefix', 'tenant-a:');

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:59', 'UTC'));

        $this->assertSame('tenant-a:requests:202606171435', BufferKeys::currentMinute());

        Carbon::setTestNow();
    }

    public function test_the_minute_key_is_always_derived_from_utc(): void
    {
        config()->set('app.timezone', 'Europe/Oslo');
        date_default_timezone_set('Europe/Oslo');

        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));

        $this->assertStringEndsWith('202606171435', BufferKeys::currentMinute());

        Carbon::setTestNow();
        date_default_timezone_set('UTC');
    }

    // -----------------------------------------------------------------
    // Capture
    // -----------------------------------------------------------------

    public function test_it_captures_a_request_as_an_event(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-17 14:35:00', 'UTC'));

        $request = Request::create('/api/orders/42', 'POST');
        $request->headers->set('User-Agent', 'PostmanRuntime/7.36.0');
        $request->headers->set('X-Request-Id', 'f0e1d2c3-b4a5-4967-8899-aabbccddeeff');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $event = $this->recorder->capture($request, new Response('', 201), microtime(true));

        $this->assertNotNull($event);
        $this->assertSame('guest', $event->actor->key());
        $this->assertSame('POST', $event->endpoint->method);
        $this->assertSame('/api/orders/42', $event->endpoint->path);
        $this->assertSame(201, $event->statusCode);
        $this->assertSame('PostmanRuntime/7.36.0', $event->userAgent);
        $this->assertSame('f0e1d2c3-b4a5-4967-8899-aabbccddeeff', $event->requestId);
        $this->assertGreaterThanOrEqual(0, $event->durationMs);

        Carbon::setTestNow();
    }

    public function test_a_null_actor_means_the_request_is_not_recorded(): void
    {
        $this->swapActorResolver(fn () => null);

        $this->assertNull($this->recorder->capture(Request::create('/api/orders'), new Response, microtime(true)));
    }

    public function test_a_throwing_actor_resolver_drops_the_record_rather_than_misfiling_it(): void
    {
        $this->swapActorResolver(fn () => throw new \RuntimeException('no actor'));

        $this->assertNull($this->recorder->capture(Request::create('/api/orders'), new Response, microtime(true)));
    }

    public function test_a_throwing_endpoint_resolver_falls_back_to_the_request_path(): void
    {
        $this->swapEndpointResolver(fn () => throw new \RuntimeException('no endpoint'));

        $event = $this->recorder->capture(Request::create('/api/orders'), new Response, microtime(true));

        $this->assertNotNull($event);
        $this->assertSame('GET:/api/orders', $event->endpoint->key());
    }

    // -----------------------------------------------------------------
    // Credentials
    // -----------------------------------------------------------------

    public function test_no_credential_is_recorded_without_a_resolver(): void
    {
        $event = $this->recorder->capture(Request::create('/api/ping'), new Response, microtime(true));

        $this->assertNotNull($event);
        $this->assertNull($event->credentialId);
    }

    public function test_a_registered_credential_resolver_is_used(): void
    {
        UsageRecorder::resolveCredentialUsing(fn (Request $request) => $request->headers->get('X-Token-Id'));

        $request = Request::create('/api/ping');
        $request->headers->set('X-Token-Id', '512');

        $event = $this->recorder->capture($request, new Response, microtime(true));

        $this->assertSame('512', $event?->credentialId);
    }

    public function test_a_configured_credential_resolver_is_used_when_none_is_registered(): void
    {
        config()->set('api_usage.actor.credential_resolver', fn (Request $r) => $r->headers->get('X-Token-Id'));

        $request = Request::create('/api/ping');
        $request->headers->set('X-Token-Id', '77');

        $this->assertSame('77', $this->recorder->capture($request, new Response, microtime(true))?->credentialId);
    }

    public function test_a_registered_credential_resolver_wins_over_the_configured_one(): void
    {
        config()->set('api_usage.actor.credential_resolver', fn () => 1);
        UsageRecorder::resolveCredentialUsing(fn () => 2);

        $this->assertSame('2', $this->recorder->capture(Request::create('/api/ping'), new Response, microtime(true))?->credentialId);
    }

    public function test_a_throwing_credential_resolver_never_breaks_the_event(): void
    {
        UsageRecorder::resolveCredentialUsing(fn () => throw new \RuntimeException('no token'));

        $event = $this->recorder->capture(Request::create('/api/ping'), new Response, microtime(true));

        $this->assertNotNull($event);
        $this->assertNull($event->credentialId);
    }

    public function test_the_bucket_key_separates_credentials_of_one_actor(): void
    {
        $this->assertSame('user:42', UsageEvent::bucketKeyFor('user:42', null));
        $this->assertSame('user:42', UsageEvent::bucketKeyFor('user:42', ''));
        $this->assertSame('user:42|cred:7', UsageEvent::bucketKeyFor('user:42', '7'));
        $this->assertSame('guest', UsageEvent::bucketKeyFor('guest', null));
    }

    public function test_the_widest_realistic_bucket_key_survives_intact(): void
    {
        $key = UsageEvent::bucketKeyFor('user:'.str_repeat('x', 64), str_repeat('y', 64));

        $this->assertSame('user:'.str_repeat('x', 64).'|cred:'.str_repeat('y', 64), $key);
        $this->assertLessThanOrEqual(UsageEvent::MAX_BUCKET_KEY_LENGTH, mb_strlen($key));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public function test_it_hashes_the_ip_address_and_never_stores_it_verbatim(): void
    {
        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $event = $this->recorder->capture($request, new Response, microtime(true));

        $this->assertNotNull($event->ipHash);
        $this->assertSame(64, strlen((string) $event->ipHash));
        $this->assertStringNotContainsString('203.0.113.7', json_encode($event->toPayload()) ?: '');
    }

    public function test_the_ip_hash_uses_the_configured_salt(): void
    {
        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        config()->set('api_usage.privacy.ip_hash_salt', 'salt-one');
        $first = $this->recorder->capture($request, new Response, microtime(true))?->ipHash;

        config()->set('api_usage.privacy.ip_hash_salt', 'salt-two');
        $second = $this->recorder->capture($request, new Response, microtime(true))?->ipHash;

        $this->assertNotSame($first, $second);
    }

    public function test_ip_recording_can_be_disabled_entirely(): void
    {
        config()->set('api_usage.privacy.hash_ips', false);

        $request = Request::create('/api/ping');
        $request->server->set('REMOTE_ADDR', '203.0.113.7');

        $this->assertNull($this->recorder->capture($request, new Response, microtime(true))?->ipHash);
    }

    public function test_user_agent_recording_can_be_disabled(): void
    {
        config()->set('api_usage.privacy.record_user_agent', false);

        $request = Request::create('/api/ping');
        $request->headers->set('User-Agent', 'curl/8.4.0');

        $this->assertNull($this->recorder->capture($request, new Response, microtime(true))?->userAgent);
    }

    public function test_no_body_cookie_or_authorization_data_reaches_the_payload(): void
    {
        $request = Request::create('/api/orders', 'POST', ['secret_field' => 'hunter2']);
        $request->headers->set('Authorization', 'Bearer super-secret-token');
        $request->headers->set('Cookie', 'session=abc123');

        $response = new Response('{"card":"4111111111111111"}', 200);

        $payload = json_encode($this->recorder->capture($request, $response, microtime(true))?->toPayload()) ?: '';

        $this->assertStringNotContainsString('hunter2', $payload);
        $this->assertStringNotContainsString('super-secret-token', $payload);
        $this->assertStringNotContainsString('session=abc123', $payload);
        $this->assertStringNotContainsString('4111111111111111', $payload);
    }

    public function test_labels_are_never_persisted(): void
    {
        $this->swapActorResolver(fn () => UsageActor::organization(42, 'Acme Inc'));

        $payload = $this->recorder->capture(Request::create('/api/ping'), new Response, microtime(true))?->toPayload();

        $this->assertSame('organization', $payload['actor_type']);
        $this->assertArrayNotHasKey('actor_label', (array) $payload);
        $this->assertStringNotContainsString('Acme Inc', json_encode($payload) ?: '');
    }

    public function test_it_falls_back_through_the_configured_request_id_headers(): void
    {
        $request = Request::create('/api/ping');
        $request->headers->set('X-Correlation-Id', 'corr-123');

        $this->assertSame('corr-123', $this->recorder->capture($request, new Response, microtime(true))?->requestId);
    }

    public function test_request_id_headers_can_be_reconfigured(): void
    {
        config()->set('api_usage.privacy.request_id_headers', ['X-Trace-Id']);

        $request = Request::create('/api/ping');
        $request->headers->set('X-Request-Id', 'ignored');
        $request->headers->set('X-Trace-Id', 'traced');

        $this->assertSame('traced', $this->recorder->capture($request, new Response, microtime(true))?->requestId);
    }

    // -----------------------------------------------------------------
    // Sampling and exclusions
    // -----------------------------------------------------------------

    public function test_excluded_paths_are_not_recorded(): void
    {
        config()->set('api_usage.except', ['up', 'internal/*']);

        $this->assertFalse(UsageRecorder::shouldRecord(Request::create('/up')));
        $this->assertFalse(UsageRecorder::shouldRecord(Request::create('/internal/metrics')));
        $this->assertTrue(UsageRecorder::shouldRecord(Request::create('/api/orders')));
    }

    public function test_recording_is_skipped_when_the_package_is_disabled(): void
    {
        config()->set('api_usage.enabled', false);

        $this->assertFalse(UsageRecorder::shouldRecord(Request::create('/api/orders')));
    }

    public function test_a_zero_sampling_rate_records_nothing(): void
    {
        config()->set('api_usage.sampling.rate', 0.0);

        $this->assertFalse(UsageRecorder::shouldRecord(Request::create('/api/orders')));
    }

    public function test_sampling_rates_outside_the_valid_range_are_clamped(): void
    {
        config()->set('api_usage.sampling.rate', 5.0);
        $this->assertTrue(UsageRecorder::shouldRecord(Request::create('/api/orders')));

        config()->set('api_usage.sampling.rate', -2.0);
        $this->assertFalse(UsageRecorder::shouldRecord(Request::create('/api/orders')));
    }

    // -----------------------------------------------------------------
    // Serialization
    // -----------------------------------------------------------------

    public function test_the_payload_carries_a_version_and_round_trips_into_a_row(): void
    {
        $this->swapActorResolver(fn () => UsageActor::organization(42));
        UsageRecorder::resolveCredentialUsing(fn () => 7);

        $request = Request::create('/api/orders/9');
        $payload = $this->recorder->capture($request, new Response('', 204), microtime(true))?->toPayload();

        $this->assertSame(UsageEvent::VERSION, $payload['v']);

        $row = UsageRecorder::prepareForInsert((array) $payload);

        $this->assertNotNull($row);
        $this->assertSame('organization', $row['actor_type']);
        $this->assertSame('42', $row['actor_id']);
        $this->assertSame('organization:42', $row['actor_key']);
        $this->assertSame('7', $row['credential_id']);
        $this->assertSame('organization:42|cred:7', $row['bucket_key']);
        $this->assertSame('GET:/api/orders/9', $row['endpoint_key']);
        $this->assertSame(204, $row['status_code']);
    }

    public function test_a_payload_from_an_unknown_version_is_discarded(): void
    {
        $entry = $this->validPayload();
        $entry['v'] = 99;

        $this->assertNull(UsageRecorder::prepareForInsert($entry));
    }

    public function test_a_payload_with_no_version_at_all_is_discarded(): void
    {
        $entry = $this->validPayload();
        unset($entry['v']);

        $this->assertNull(UsageRecorder::prepareForInsert($entry));
    }

    public function test_a_payload_missing_required_fields_is_discarded(): void
    {
        foreach (['requested_at', 'actor_key', 'method', 'path', 'endpoint_key', 'status_code', 'duration_ms'] as $field) {
            $entry = $this->validPayload();
            unset($entry[$field]);

            $this->assertNull(UsageRecorder::prepareForInsert($entry), "Missing {$field} should be rejected.");
        }

        $this->assertNull(UsageRecorder::prepareForInsert([]));
    }

    public function test_it_coerces_and_clamps_stored_values(): void
    {
        $row = UsageRecorder::prepareForInsert(array_merge($this->validPayload(), [
            'status_code' => '404',
            'duration_ms' => -17,
            'method' => 'GET',
        ]));

        $this->assertNotNull($row);
        $this->assertSame(404, $row['status_code']);
        $this->assertSame(0, $row['duration_ms']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validPayload(): array
    {
        return [
            'v' => UsageEvent::VERSION,
            'requested_at' => '2026-06-17 14:35:00',
            'actor_type' => 'user',
            'actor_id' => '1',
            'actor_key' => 'user:1',
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
        ];
    }

    private function swapActorResolver(\Closure $resolve): void
    {
        $this->app()->bind(ResolvesUsageActor::class, fn () => new class($resolve) implements ResolvesUsageActor
        {
            public function __construct(private \Closure $resolve) {}

            public function resolve(Request $request): ?UsageActor
            {
                return ($this->resolve)($request);
            }
        });
    }

    private function swapEndpointResolver(\Closure $resolve): void
    {
        $this->app()->bind(ResolvesUsageEndpoint::class, fn () => new class($resolve) implements ResolvesUsageEndpoint
        {
            public function __construct(private \Closure $resolve) {}

            public function resolve(Request $request): UsageEndpoint
            {
                return ($this->resolve)($request);
            }
        });
    }
}
