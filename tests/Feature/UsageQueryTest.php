<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Facades\ApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class UsageQueryTest extends TestCase
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

    public function test_an_empty_period_returns_an_empty_summary(): void
    {
        $summary = ApiUsage::usage()->today()->summary();

        $this->assertFalse($summary->hasUsage());
        $this->assertSame(0, $summary->totalRequests);
        $this->assertNull($summary->averageDurationMs);
        $this->assertSame(0.0, $summary->errorRate());
    }

    public function test_it_summarizes_a_period(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 6, 'responses_2xx' => 4, 'responses_4xx' => 1, 'responses_5xx' => 1, 'total_duration_ms' => 300, 'min_duration_ms' => 10, 'max_duration_ms' => 120]);

        $summary = ApiUsage::usage()->today()->summary();

        $this->assertSame(6, $summary->totalRequests);
        $this->assertSame(4, $summary->successfulRequests);
        $this->assertSame(50.0, $summary->averageDurationMs);
        $this->assertSame(10, $summary->minDurationMs);
        $this->assertSame(120, $summary->maxDurationMs);
        $this->assertSame(0.3333, $summary->errorRate());
    }

    public function test_today_and_yesterday_select_different_days(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 3]);
        $this->summary('2026-06-16', ['total_requests' => 5]);

        $this->assertSame(3, ApiUsage::usage()->today()->summary()->totalRequests);
        $this->assertSame(5, ApiUsage::usage()->yesterday()->summary()->totalRequests);
    }

    public function test_last_days_includes_today(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 1]);
        $this->summary('2026-06-16', ['total_requests' => 2]);
        $this->summary('2026-06-14', ['total_requests' => 4]);

        $this->assertSame(1, ApiUsage::usage()->lastDays(1)->summary()->totalRequests);
        $this->assertSame(3, ApiUsage::usage()->lastDays(2)->summary()->totalRequests);
        $this->assertSame(7, ApiUsage::usage()->lastDays(7)->summary()->totalRequests);
    }

    public function test_this_month_and_last_month_select_different_ranges(): void
    {
        $this->summary('2026-06-01', ['total_requests' => 2]);
        $this->summary('2026-06-30', ['total_requests' => 3]);
        $this->summary('2026-05-31', ['total_requests' => 9]);

        $this->assertSame(5, ApiUsage::usage()->thisMonth()->summary()->totalRequests);
        $this->assertSame(9, ApiUsage::usage()->lastMonth()->summary()->totalRequests);
    }

    public function test_this_week_selects_the_current_week(): void
    {
        // 2026-06-17 is a Wednesday.
        $this->summary('2026-06-17', ['total_requests' => 2]);
        $this->summary('2026-06-15', ['total_requests' => 3]);
        $this->summary('2026-06-07', ['total_requests' => 9]);

        $this->assertSame(5, ApiUsage::usage()->thisWeek()->summary()->totalRequests);
    }

    public function test_between_is_inclusive_at_both_ends(): void
    {
        $this->summary('2026-06-10', ['total_requests' => 1]);
        $this->summary('2026-06-12', ['total_requests' => 2]);
        $this->summary('2026-06-14', ['total_requests' => 4]);

        $summary = ApiUsage::usage()
            ->between(Carbon::parse('2026-06-10', 'UTC'), Carbon::parse('2026-06-12', 'UTC'))
            ->summary();

        $this->assertSame(3, $summary->totalRequests);
    }

    public function test_a_reversed_range_is_swapped_rather_than_returning_nothing(): void
    {
        $this->summary('2026-06-11', ['total_requests' => 7]);

        $summary = ApiUsage::usage()
            ->between(Carbon::parse('2026-06-12', 'UTC'), Carbon::parse('2026-06-10', 'UTC'))
            ->summary();

        $this->assertSame(7, $summary->totalRequests);
    }

    public function test_monthly_rows_never_leak_into_a_daily_query(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 3]);
        $this->summary('2026-06-01', ['total_requests' => 999], [], 'month');

        $this->assertSame(3, ApiUsage::usage()->thisMonth()->summary()->totalRequests);
    }

    public function test_it_filters_by_actor(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], $this->actor('organization', '42'));
        $this->summary('2026-06-17', ['total_requests' => 6], $this->actor('organization', '43'));

        $summary = ApiUsage::usage()->today()->forActor(UsageActor::organization(42))->summary();

        $this->assertSame(4, $summary->totalRequests);
    }

    public function test_actors_of_different_types_with_the_same_id_do_not_collide(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], $this->actor('organization', '42'));
        $this->summary('2026-06-17', ['total_requests' => 6], $this->actor('user', '42'));

        $this->assertSame(4, ApiUsage::usage()->today()->forActor(UsageActor::organization(42))->summary()->totalRequests);
        $this->assertSame(6, ApiUsage::usage()->today()->forActor(UsageActor::user(42))->summary()->totalRequests);
    }

    public function test_it_filters_by_actor_type(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], $this->actor('organization', '42'));
        $this->summary('2026-06-17', ['total_requests' => 6], $this->actor('organization', '43'));
        $this->summary('2026-06-17', ['total_requests' => 1], $this->actor('user', '1'));

        $this->assertSame(10, ApiUsage::usage()->today()->forActorType('organization')->summary()->totalRequests);
    }

    public function test_it_filters_by_credential(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], $this->actor('user', '7', '1'));
        $this->summary('2026-06-17', ['total_requests' => 6], $this->actor('user', '7', '2'));

        $summary = ApiUsage::usage()->today()->forActor(UsageActor::user(7))->forCredential(1)->summary();

        $this->assertSame(4, $summary->totalRequests);
        $this->assertSame(10, ApiUsage::usage()->today()->forActor(UsageActor::user(7))->summary()->totalRequests);
    }

    public function test_it_filters_by_endpoint(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], [], 'day', 'GET:api.orders.index');
        $this->summary('2026-06-17', ['total_requests' => 6], [], 'day', 'GET:api.orders.show');

        $this->assertSame(4, ApiUsage::usage()->today()->forEndpoint('GET:api.orders.index')->summary()->totalRequests);
    }

    public function test_count_is_the_shorthand_for_total_requests(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4]);
        $this->summary('2026-06-17', ['total_requests' => 6], [], 'day', 'GET:api.orders.show');

        $this->assertSame(10, ApiUsage::usage()->today()->count());
        $this->assertSame(0, ApiUsage::usage()->yesterday()->count());
    }

    public function test_the_query_is_immutable_so_a_base_query_can_be_reused(): void
    {
        $this->summary('2026-06-17', ['total_requests' => 4], $this->actor('user', '1'));
        $this->summary('2026-06-17', ['total_requests' => 6], $this->actor('user', '2'));

        $today = ApiUsage::usage()->today();

        $this->assertSame(4, $today->forActor(UsageActor::user(1))->summary()->totalRequests);
        $this->assertSame(6, $today->forActor(UsageActor::user(2))->summary()->totalRequests);
        $this->assertSame(10, $today->summary()->totalRequests);
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(string $type, string $id, ?string $credentialId = null): array
    {
        $actorKey = $type.':'.$id;

        return [
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_key' => $actorKey,
            'credential_id' => $credentialId,
            'bucket_key' => $credentialId === null ? $actorKey : $actorKey.'|cred:'.$credentialId,
        ];
    }

    /**
     * @param  array<string, int>  $counters
     * @param  array<string, mixed>  $actor
     */
    private function summary(
        string $date,
        array $counters,
        array $actor = [],
        string $periodType = 'day',
        string $endpointKey = 'GET:api.orders.index',
    ): void {
        ApiUsageSummary::query()->create(array_merge([
            'period_type' => $periodType,
            'period_start' => $date,
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'actor_key' => 'guest',
            'credential_id' => null,
            'bucket_key' => 'guest',
            'endpoint_key' => $endpointKey,
            'method' => 'GET',
            'route_name' => 'api.orders.index',
            'route_uri' => '/api/orders',
        ], $actor, $counters));
    }
}
