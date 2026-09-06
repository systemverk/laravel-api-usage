<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Facades\ApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;
use Systemverk\LaravelApiUsage\Usage\EndpointUsage;

class EndpointQueryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-17 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_an_empty_period_returns_an_empty_collection(): void
    {
        $this->assertTrue(ApiUsage::endpoints()->today()->mostUsed()->isEmpty());
        $this->assertTrue(ApiUsage::endpoints()->today()->slowest()->isEmpty());
        $this->assertTrue(ApiUsage::endpoints()->today()->mostErrors()->isEmpty());
    }

    public function test_most_used_orders_by_request_count(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 5]);
        $this->endpoint('GET:api.orders.show', ['total_requests' => 20]);
        $this->endpoint('POST:api.orders.store', ['total_requests' => 12]);

        $keys = ApiUsage::endpoints()->today()->mostUsed()->map->endpointKey->all();

        $this->assertSame(['GET:api.orders.show', 'POST:api.orders.store', 'GET:api.orders.index'], $keys);
    }

    public function test_slowest_orders_by_mean_duration_not_by_total(): void
    {
        // Called often but fast overall.
        $this->endpoint('GET:api.orders.index', ['total_requests' => 100, 'total_duration_ms' => 1000]);
        // Called rarely but slow per call.
        $this->endpoint('GET:api.reports.build', ['total_requests' => 2, 'total_duration_ms' => 4000]);

        $slowest = ApiUsage::endpoints()->today()->slowest()->first();

        $this->assertInstanceOf(EndpointUsage::class, $slowest);
        $this->assertSame('GET:api.reports.build', $slowest->endpointKey);
        $this->assertSame(2000.0, $slowest->averageDurationMs());
    }

    public function test_most_errors_counts_client_and_server_errors(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 100, 'responses_4xx' => 1, 'responses_5xx' => 1]);
        $this->endpoint('GET:api.orders.show', ['total_requests' => 10, 'responses_4xx' => 5]);
        $this->endpoint('POST:api.orders.store', ['total_requests' => 10, 'responses_5xx' => 7]);

        $worst = ApiUsage::endpoints()->today()->mostErrors()->first();

        $this->assertInstanceOf(EndpointUsage::class, $worst);
        $this->assertSame('POST:api.orders.store', $worst->endpointKey);
        $this->assertSame(7, $worst->serverErrors());
    }

    public function test_the_limit_is_respected(): void
    {
        foreach (range(1, 15) as $i) {
            $this->endpoint("GET:api.route{$i}", ['total_requests' => $i]);
        }

        $this->assertCount(10, ApiUsage::endpoints()->today()->mostUsed());
        $this->assertCount(3, ApiUsage::endpoints()->today()->mostUsed(3));
        $this->assertCount(15, ApiUsage::endpoints()->today()->all());
    }

    public function test_ties_are_broken_deterministically_by_endpoint_key(): void
    {
        $this->endpoint('GET:b', ['total_requests' => 5]);
        $this->endpoint('GET:a', ['total_requests' => 5]);
        $this->endpoint('GET:c', ['total_requests' => 5]);

        $this->assertSame(
            ['GET:a', 'GET:b', 'GET:c'],
            ApiUsage::endpoints()->today()->mostUsed()->map->endpointKey->all()
        );
    }

    public function test_days_are_summed_across_the_period(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 4], '2026-06-16');
        $this->endpoint('GET:api.orders.index', ['total_requests' => 6], '2026-06-17');

        $this->assertSame(10, ApiUsage::endpoints()->lastDays(2)->mostUsed()->first()?->requestsTotal());
        $this->assertSame(6, ApiUsage::endpoints()->today()->mostUsed()->first()?->requestsTotal());
    }

    public function test_one_endpoint_is_one_row_even_across_many_actors(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 4], '2026-06-17', $this->actor('user', '1'));
        $this->endpoint('GET:api.orders.index', ['total_requests' => 6], '2026-06-17', $this->actor('user', '2'));

        $endpoints = ApiUsage::endpoints()->today()->mostUsed();

        $this->assertCount(1, $endpoints);
        $this->assertSame(10, $endpoints->first()?->requestsTotal());
    }

    public function test_it_can_be_narrowed_to_one_actor(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 4], '2026-06-17', $this->actor('user', '1'));
        $this->endpoint('GET:api.orders.index', ['total_requests' => 6], '2026-06-17', $this->actor('user', '2'));

        $endpoints = ApiUsage::endpoints()->today()->forActor(UsageActor::user(1))->mostUsed();

        $this->assertSame(4, $endpoints->first()?->requestsTotal());
    }

    public function test_it_exposes_the_route_metadata(): void
    {
        $this->endpoint('GET:api.orders.show', ['total_requests' => 1]);

        $endpoint = ApiUsage::endpoints()->today()->mostUsed()->first();

        $this->assertInstanceOf(EndpointUsage::class, $endpoint);
        $this->assertSame('GET', $endpoint->method);
        $this->assertSame('api.orders.show', $endpoint->routeName);
        $this->assertSame('/api/orders/{order}', $endpoint->routeUri);
        $this->assertSame('api.orders.show', $endpoint->label());
    }

    public function test_the_shared_summary_covers_every_selected_endpoint(): void
    {
        $this->endpoint('GET:api.orders.index', ['total_requests' => 4, 'responses_2xx' => 4]);
        $this->endpoint('GET:api.orders.show', ['total_requests' => 6, 'responses_5xx' => 6]);

        $summary = ApiUsage::endpoints()->today()->summary();

        $this->assertSame(10, $summary->totalRequests);
        $this->assertSame(6, $summary->serverErrors);
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(string $type, string $id): array
    {
        return [
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_key' => $type.':'.$id,
            'bucket_key' => $type.':'.$id,
        ];
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, mixed>  $actor
     */
    private function endpoint(string $endpointKey, array $counters, string $date = '2026-06-17', array $actor = []): void
    {
        [$method, $identity] = explode(':', $endpointKey, 2);

        ApiUsageSummary::query()->create(array_merge([
            'period_type' => 'day',
            'period_start' => $date,
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'actor_key' => 'guest',
            'credential_id' => null,
            'bucket_key' => 'guest',
            'endpoint_key' => $endpointKey,
            'method' => $method,
            'route_name' => $identity,
            'route_uri' => '/api/orders/{order}',
        ], $actor, $counters));
    }
}
