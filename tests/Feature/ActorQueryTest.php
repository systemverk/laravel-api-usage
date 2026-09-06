<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Facades\ApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;
use Systemverk\LaravelApiUsage\Usage\ActorUsage;

class ActorQueryTest extends TestCase
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
        $this->assertTrue(ApiUsage::actors()->today()->mostActive()->isEmpty());
        $this->assertTrue(ApiUsage::actors()->today()->mostErrors()->isEmpty());
    }

    public function test_most_active_orders_by_request_count(): void
    {
        $this->actor('organization', '1', ['total_requests' => 5]);
        $this->actor('organization', '2', ['total_requests' => 20]);
        $this->actor('organization', '3', ['total_requests' => 12]);

        $this->assertSame(
            ['organization:2', 'organization:3', 'organization:1'],
            ApiUsage::actors()->today()->mostActive()->map->actorKey->all()
        );
    }

    public function test_the_guest_actor_can_appear_in_the_results(): void
    {
        $this->actor('guest', 'guest', ['total_requests' => 50], 'guest');
        $this->actor('user', '1', ['total_requests' => 5]);

        $this->assertSame('guest', ApiUsage::actors()->today()->mostActive()->first()?->actorKey);
    }

    public function test_different_actor_types_with_the_same_id_never_collide(): void
    {
        $this->actor('user', '42', ['total_requests' => 5]);
        $this->actor('organization', '42', ['total_requests' => 9]);

        $actors = ApiUsage::actors()->today()->mostActive();

        $this->assertCount(2, $actors);
        $this->assertSame('organization:42', $actors->first()?->actorKey);
        $this->assertSame(5, $actors->last()?->requestsTotal());
    }

    public function test_credentials_of_one_actor_roll_up_into_that_actor(): void
    {
        $this->actor('user', '7', ['total_requests' => 4], null, '1');
        $this->actor('user', '7', ['total_requests' => 6], null, '2');

        $actors = ApiUsage::actors()->today()->mostActive();

        $this->assertCount(1, $actors);
        $this->assertSame(10, $actors->first()?->requestsTotal());
    }

    public function test_most_errors_counts_client_and_server_errors(): void
    {
        $this->actor('user', '1', ['total_requests' => 100, 'responses_4xx' => 2]);
        $this->actor('user', '2', ['total_requests' => 10, 'responses_5xx' => 9]);

        $worst = ApiUsage::actors()->today()->mostErrors()->first();

        $this->assertInstanceOf(ActorUsage::class, $worst);
        $this->assertSame('user:2', $worst->actorKey);
        $this->assertSame(9, $worst->serverErrors());
    }

    public function test_the_limit_is_respected(): void
    {
        foreach (range(1, 15) as $i) {
            $this->actor('user', (string) $i, ['total_requests' => $i]);
        }

        $this->assertCount(10, ApiUsage::actors()->today()->mostActive());
        $this->assertCount(3, ApiUsage::actors()->today()->mostActive(3));
        $this->assertCount(15, ApiUsage::actors()->today()->all());
    }

    public function test_ties_are_broken_deterministically_by_actor_key(): void
    {
        $this->actor('user', 'c', ['total_requests' => 5]);
        $this->actor('user', 'a', ['total_requests' => 5]);
        $this->actor('user', 'b', ['total_requests' => 5]);

        $this->assertSame(
            ['user:a', 'user:b', 'user:c'],
            ApiUsage::actors()->today()->mostActive()->map->actorKey->all()
        );
    }

    public function test_a_result_can_be_turned_back_into_an_actor(): void
    {
        $this->actor('organization', '42', ['total_requests' => 3]);

        $result = ApiUsage::actors()->today()->mostActive()->first();

        $this->assertSame('organization:42', $result?->actor()?->key());
        $this->assertSame(
            3,
            ApiUsage::usage()->today()->forActor($result->actor())->summary()->totalRequests
        );
    }

    public function test_it_can_be_narrowed_to_one_actor_type(): void
    {
        $this->actor('organization', '1', ['total_requests' => 5]);
        $this->actor('user', '1', ['total_requests' => 50]);

        $actors = ApiUsage::actors()->today()->forActorType('organization')->mostActive();

        $this->assertCount(1, $actors);
        $this->assertSame('organization:1', $actors->first()?->actorKey);
    }

    public function test_it_reports_the_mean_duration_per_actor(): void
    {
        $this->actor('user', '1', ['total_requests' => 4, 'total_duration_ms' => 200]);

        $this->assertSame(50.0, ApiUsage::actors()->today()->mostActive()->first()?->averageDurationMs());
    }

    /**
     * @param  array<string, int>  $counters
     */
    private function actor(
        string $type,
        string $id,
        array $counters,
        ?string $actorKey = null,
        ?string $credentialId = null,
    ): void {
        $actorKey ??= $type.':'.$id;

        ApiUsageSummary::query()->create(array_merge([
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_key' => $actorKey,
            'credential_id' => $credentialId,
            'bucket_key' => $credentialId === null ? $actorKey : $actorKey.'|cred:'.$credentialId,
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
            'route_name' => 'api.orders.index',
            'route_uri' => '/api/orders',
        ], $counters));
    }
}
