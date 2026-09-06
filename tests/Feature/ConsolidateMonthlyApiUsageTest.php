<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class ConsolidateMonthlyApiUsageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-01 04:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_sums_daily_rows_into_one_monthly_row_per_identity(): void
    {
        $this->daily('2026-06-01', ['total_requests' => 10, 'responses_2xx' => 8, 'responses_5xx' => 2]);
        $this->daily('2026-06-02', ['total_requests' => 5, 'responses_2xx' => 5]);
        $this->daily('2026-06-02', ['total_requests' => 3, 'responses_4xx' => 3], $this->actor('user', '7'));

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $guest = ApiUsageSummary::query()->monthly()->where('actor_key', 'guest')->firstOrFail();

        $this->assertSame(15, $guest->total_requests);
        $this->assertSame(13, $guest->responses_2xx);
        $this->assertSame(2, $guest->responses_5xx);

        $user = ApiUsageSummary::query()->monthly()->where('actor_key', 'user:7')->firstOrFail();

        $this->assertSame(3, $user->total_requests);
        $this->assertSame(3, $user->responses_4xx);
        $this->assertSame('7', $user->actor_id);
    }

    public function test_it_keeps_credentials_of_one_actor_in_separate_monthly_rows(): void
    {
        $this->daily('2026-06-01', ['total_requests' => 4], $this->actor('user', '7', '1'));
        $this->daily('2026-06-02', ['total_requests' => 6], $this->actor('user', '7', '1'));
        $this->daily('2026-06-02', ['total_requests' => 3], $this->actor('user', '7', '2'));

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $first = ApiUsageSummary::query()->monthly()->where('bucket_key', 'user:7|cred:1')->firstOrFail();

        $this->assertSame(10, $first->total_requests);
        $this->assertSame('7', $first->actor_id);
        $this->assertSame('1', $first->credential_id);

        $second = ApiUsageSummary::query()->monthly()->where('bucket_key', 'user:7|cred:2')->firstOrFail();

        $this->assertSame(3, $second->total_requests);
    }

    public function test_it_keeps_endpoints_apart(): void
    {
        $this->daily('2026-06-01', ['total_requests' => 4], [], 'GET:api.orders.index');
        $this->daily('2026-06-01', ['total_requests' => 6], [], 'GET:api.orders.show');

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(2, ApiUsageSummary::query()->monthly()->count());
        $this->assertSame(
            4,
            ApiUsageSummary::query()->monthly()->where('endpoint_key', 'GET:api.orders.index')->value('total_requests')
        );
    }

    public function test_it_carries_every_status_bucket_across(): void
    {
        $this->daily('2026-06-01', [
            'total_requests' => 5,
            'responses_1xx' => 1,
            'responses_2xx' => 1,
            'responses_3xx' => 1,
            'responses_4xx' => 1,
            'responses_5xx' => 1,
        ]);

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $summary = ApiUsageSummary::query()->monthly()->firstOrFail();

        $this->assertSame(1, $summary->responses_1xx);
        $this->assertSame(1, $summary->responses_3xx);
    }

    public function test_it_combines_duration_totals_and_extremes(): void
    {
        $this->daily('2026-06-01', [
            'total_requests' => 4, 'total_duration_ms' => 200, 'min_duration_ms' => 20, 'max_duration_ms' => 90,
        ]);
        $this->daily('2026-06-02', [
            'total_requests' => 6, 'total_duration_ms' => 300, 'min_duration_ms' => 5, 'max_duration_ms' => 400,
        ]);

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $summary = ApiUsageSummary::query()->monthly()->firstOrFail();

        $this->assertSame(500, $summary->total_duration_ms);
        $this->assertSame(5, $summary->min_duration_ms);
        $this->assertSame(400, $summary->max_duration_ms);
    }

    public function test_it_ignores_days_outside_the_month(): void
    {
        $this->daily('2026-05-31', ['total_requests' => 100]);
        $this->daily('2026-06-15', ['total_requests' => 4]);
        $this->daily('2026-07-01', ['total_requests' => 100]);

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(4, ApiUsageSummary::query()->monthly()->value('total_requests'));
    }

    public function test_it_never_folds_monthly_rows_back_into_themselves(): void
    {
        $this->daily('2026-06-01', ['total_requests' => 4]);

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);
        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->monthly()->count());
        $this->assertSame(4, ApiUsageSummary::query()->monthly()->value('total_requests'));
    }

    public function test_it_defaults_to_the_previous_month(): void
    {
        $this->daily('2026-06-10', ['total_requests' => 2]);

        $this->artisan('api-usage:consolidate-monthly')->assertExitCode(0);

        $this->assertSame('2026-06-01', ApiUsageSummary::query()->monthly()->firstOrFail()->period_start->toDateString());
    }

    public function test_it_reports_when_there_is_nothing_to_consolidate(): void
    {
        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])
            ->expectsOutputToContain('No daily API usage summaries found')
            ->assertExitCode(0);
    }

    public function test_it_rejects_an_invalid_month_option(): void
    {
        foreach (['2026-13', 'june'] as $month) {
            $this->artisan('api-usage:consolidate-monthly', ['--month' => $month])
                ->expectsOutputToContain('Invalid --month')
                ->assertExitCode(1);
        }
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_usage.enabled', false);
        $this->daily('2026-06-01', ['total_requests' => 4]);

        $this->artisan('api-usage:consolidate-monthly', ['--month' => '2026-06'])->assertExitCode(0);

        $this->assertSame(0, ApiUsageSummary::query()->monthly()->count());
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
    private function daily(string $date, array $counters, array $actor = [], string $endpointKey = 'GET:api.orders.index'): void
    {
        ApiUsageSummary::query()->create(array_merge([
            'period_type' => 'day',
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
