<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class ConsolidateDailyApiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-06-18 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_counts_every_status_class_separately(): void
    {
        foreach ([100, 200, 201, 301, 404, 422, 500, 503] as $status) {
            $this->request(['status_code' => $status]);
        }

        $this->consolidate();

        $summary = ApiUsageSummary::query()->daily()->firstOrFail();

        $this->assertSame(8, $summary->total_requests);
        $this->assertSame(1, $summary->responses_1xx);
        $this->assertSame(2, $summary->responses_2xx);
        $this->assertSame(1, $summary->responses_3xx);
        $this->assertSame(2, $summary->responses_4xx);
        $this->assertSame(2, $summary->responses_5xx);
    }

    public function test_the_total_equals_the_sum_of_the_status_buckets(): void
    {
        foreach ([100, 204, 302, 400, 500] as $status) {
            $this->request(['status_code' => $status]);
        }

        $this->consolidate();

        $summary = ApiUsageSummary::query()->daily()->firstOrFail();

        $this->assertSame(
            $summary->total_requests,
            $summary->responses_1xx + $summary->responses_2xx + $summary->responses_3xx
                + $summary->responses_4xx + $summary->responses_5xx
        );
    }

    public function test_it_records_duration_totals_and_extremes(): void
    {
        foreach ([40, 10, 90] as $duration) {
            $this->request(['duration_ms' => $duration]);
        }

        $this->consolidate();

        $summary = ApiUsageSummary::query()->daily()->firstOrFail();

        $this->assertSame(140, $summary->total_duration_ms);
        $this->assertSame(10, $summary->min_duration_ms);
        $this->assertSame(90, $summary->max_duration_ms);
    }

    public function test_it_separates_guests_from_authenticated_actors(): void
    {
        $this->request($this->guest());
        $this->request($this->guest());
        $this->request($this->actor('user', '7'));

        $this->consolidate();

        $this->assertSame(2, ApiUsageSummary::query()->where('actor_key', 'guest')->value('total_requests'));
        $this->assertSame(1, ApiUsageSummary::query()->where('actor_key', 'user:7')->value('total_requests'));
    }

    public function test_different_actor_types_never_collide(): void
    {
        $this->request($this->actor('user', '42'));
        $this->request($this->actor('organization', '42'));

        $this->consolidate();

        $this->assertSame(2, ApiUsageSummary::query()->count());
        $this->assertSame(1, ApiUsageSummary::query()->where('actor_key', 'user:42')->value('total_requests'));
        $this->assertSame(1, ApiUsageSummary::query()->where('actor_key', 'organization:42')->value('total_requests'));
    }

    public function test_it_separates_credentials_belonging_to_the_same_actor(): void
    {
        $this->request($this->actor('user', '7', '1'));
        $this->request($this->actor('user', '7', '1'));
        $this->request($this->actor('user', '7', '2'));
        $this->request($this->actor('user', '7'));

        $this->consolidate();

        $this->assertSame(2, ApiUsageSummary::query()->where('bucket_key', 'user:7|cred:1')->value('total_requests'));
        $this->assertSame(1, ApiUsageSummary::query()->where('bucket_key', 'user:7|cred:2')->value('total_requests'));
        $this->assertSame(1, ApiUsageSummary::query()->where('bucket_key', 'user:7')->value('total_requests'));

        // The whole actor still rolls up.
        $this->assertSame(4, (int) ApiUsageSummary::query()->where('actor_id', '7')->sum('total_requests'));
    }

    public function test_dynamic_route_parameters_aggregate_under_one_endpoint(): void
    {
        $this->request($this->endpoint('api.orders.show', '/api/orders/1'));
        $this->request($this->endpoint('api.orders.show', '/api/orders/2'));
        $this->request($this->endpoint('api.orders.show', '/api/orders/3'));

        $this->consolidate();

        $this->assertSame(1, ApiUsageSummary::query()->count());
        $this->assertSame(3, ApiUsageSummary::query()->value('total_requests'));
        $this->assertSame('GET:api.orders.show', ApiUsageSummary::query()->value('endpoint_key'));
    }

    public function test_different_endpoints_are_separate_rows_for_the_same_actor(): void
    {
        $this->request($this->endpoint('api.orders.index', '/api/orders'));
        $this->request($this->endpoint('api.orders.show', '/api/orders/1'));

        $this->consolidate();

        $this->assertSame(2, ApiUsageSummary::query()->count());
    }

    public function test_it_carries_the_route_metadata_onto_the_summary(): void
    {
        $this->request($this->endpoint('api.orders.show', '/api/orders/1'));

        $this->consolidate();

        $summary = ApiUsageSummary::query()->firstOrFail();

        $this->assertSame('GET', $summary->method);
        $this->assertSame('api.orders.show', $summary->route_name);
        $this->assertSame('/api/orders/{order}', $summary->route_uri);
    }

    public function test_reruns_are_idempotent(): void
    {
        $this->request();
        $this->request();

        $this->consolidate();
        $this->consolidate();

        $this->assertSame(1, ApiUsageSummary::query()->count());
        $this->assertSame(2, ApiUsageSummary::query()->value('total_requests'));
    }

    public function test_a_rerun_reflects_requests_that_arrived_late(): void
    {
        $this->request();
        $this->consolidate();

        $this->request();
        $this->consolidate();

        $this->assertSame(1, ApiUsageSummary::query()->count());
        $this->assertSame(2, ApiUsageSummary::query()->value('total_requests'));
    }

    public function test_reruns_are_idempotent_across_every_dimension(): void
    {
        $this->request($this->actor('user', '7', '1') + $this->endpoint('api.orders.index', '/api/orders'));
        $this->request($this->actor('user', '7', '2') + $this->endpoint('api.orders.index', '/api/orders'));
        $this->request($this->actor('user', '7', '1') + $this->endpoint('api.orders.show', '/api/orders/1'));

        $this->consolidate();
        $this->consolidate();
        $this->consolidate();

        $this->assertSame(3, ApiUsageSummary::query()->count());
        $this->assertSame(3, (int) ApiUsageSummary::query()->sum('total_requests'));
    }

    public function test_it_only_consolidates_the_requested_day(): void
    {
        $this->request(['requested_at' => '2026-06-16 23:59:59']);
        $this->request(['requested_at' => '2026-06-17 00:00:00']);
        $this->request(['requested_at' => '2026-06-17 23:59:59']);
        $this->request(['requested_at' => '2026-06-18 00:00:00']);

        $this->consolidate();

        $this->assertSame(2, ApiUsageSummary::query()->value('total_requests'));
    }

    public function test_it_defaults_to_yesterday(): void
    {
        $this->request(['requested_at' => '2026-06-17 10:00:00']);

        $this->artisan('api-usage:consolidate-daily')->assertExitCode(0);

        $this->assertSame('2026-06-17', ApiUsageSummary::query()->firstOrFail()->period_start->toDateString());
    }

    public function test_the_today_flag_consolidates_the_current_day(): void
    {
        $this->request(['requested_at' => '2026-06-18 03:00:00']);
        $this->request(['requested_at' => '2026-06-17 10:00:00']);

        $this->artisan('api-usage:consolidate-daily', ['--today' => true])->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->count());
        $this->assertSame('2026-06-18', ApiUsageSummary::query()->firstOrFail()->period_start->toDateString());
    }

    public function test_today_and_date_cannot_be_combined(): void
    {
        $this->artisan('api-usage:consolidate-daily', ['--today' => true, '--date' => '2026-06-17'])
            ->expectsOutputToContain('not both')
            ->assertExitCode(1);
    }

    public function test_it_reports_when_there_is_nothing_to_consolidate(): void
    {
        $this->artisan('api-usage:consolidate-daily', ['--date' => '2026-06-17'])
            ->expectsOutputToContain('No API usage found')
            ->assertExitCode(0);

        $this->assertSame(0, ApiUsageSummary::query()->count());
    }

    public function test_it_processes_more_rows_than_one_chunk(): void
    {
        config()->set('api_usage.database.consolidation_chunk_size', 100);

        for ($i = 0; $i < 250; $i++) {
            $this->request(['status_code' => $i % 2 === 0 ? 200 : 500]);
        }

        $this->consolidate();

        $summary = ApiUsageSummary::query()->firstOrFail();

        $this->assertSame(250, $summary->total_requests);
        $this->assertSame(125, $summary->responses_2xx);
        $this->assertSame(125, $summary->responses_5xx);
    }

    public function test_it_rejects_an_invalid_date_option(): void
    {
        foreach (['2026-99-01', 'yesterday', '2026-02-30'] as $date) {
            $this->artisan('api-usage:consolidate-daily', ['--date' => $date])
                ->expectsOutputToContain('Invalid --date')
                ->assertExitCode(1);
        }
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_usage.enabled', false);
        $this->request();

        $this->consolidate();

        $this->assertSame(0, ApiUsageSummary::query()->count());
    }

    private function consolidate(string $date = '2026-06-17'): void
    {
        $this->artisan('api-usage:consolidate-daily', ['--date' => $date])->assertExitCode(0);
    }

    /**
     * @return array<string, mixed>
     */
    private function guest(): array
    {
        return $this->actor('guest', 'guest');
    }

    /**
     * @return array<string, mixed>
     */
    private function actor(string $type, string $id, ?string $credentialId = null): array
    {
        $actorKey = $type === 'guest' ? 'guest' : $type.':'.$id;

        return [
            'actor_type' => $type,
            'actor_id' => $id,
            'actor_key' => $actorKey,
            'credential_id' => $credentialId,
            'bucket_key' => UsageEvent::bucketKeyFor($actorKey, $credentialId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function endpoint(string $routeName, string $path): array
    {
        return [
            'route_name' => $routeName,
            'route_uri' => '/api/orders/{order}',
            'path' => $path,
            'endpoint_key' => 'GET:'.$routeName,
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function request(array $overrides = []): ApiUsageRequest
    {
        return ApiUsageRequest::query()->create(array_merge([
            'requested_at' => '2026-06-17 12:00:00',
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'actor_key' => 'guest',
            'credential_id' => null,
            'bucket_key' => 'guest',
            'method' => 'GET',
            'route_name' => null,
            'route_uri' => null,
            'path' => '/api/orders',
            'endpoint_key' => 'GET:/api/orders',
            'status_code' => 200,
            'duration_ms' => 10,
        ], $overrides));
    }
}
