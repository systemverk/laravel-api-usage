<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class PruneApiUsageTest extends TestCase
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

    public function test_it_deletes_raw_rows_past_the_retention_window(): void
    {
        config()->set('api_usage.retention.raw_days', 30);

        // 30 days before 2026-06-17 is 2026-05-18, so only the two older rows go.
        $this->request('2026-06-16 12:00:00');
        $this->request('2026-04-20 12:00:00');
        $this->request('2026-01-01 12:00:00');

        $this->artisan('api-usage:prune')
            ->expectsOutputToContain('Pruned 2 raw API usage rows older than 30 days.')
            ->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
    }

    public function test_summaries_outlive_the_raw_rows_they_came_from(): void
    {
        config()->set('api_usage.retention.raw_days', 30);
        config()->set('api_usage.retention.daily_days', 730);

        $this->request('2026-01-01 12:00:00');
        $this->summary('day', '2026-01-01');

        $this->artisan('api-usage:prune')->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
        $this->assertSame(1, ApiUsageSummary::query()->count());
    }

    public function test_daily_summaries_are_pruned_on_their_own_schedule(): void
    {
        config()->set('api_usage.retention.daily_days', 10);

        $this->summary('day', '2026-06-16');
        $this->summary('day', '2026-05-01');

        $this->artisan('api-usage:prune')->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->daily()->count());
        $this->assertSame('2026-06-16', ApiUsageSummary::query()->daily()->firstOrFail()->period_start->toDateString());
    }

    public function test_daily_summaries_are_kept_indefinitely_when_retention_is_zero(): void
    {
        config()->set('api_usage.retention.daily_days', 0);

        $this->summary('day', '2020-01-01');

        $this->artisan('api-usage:prune')
            ->expectsOutputToContain('Daily summaries are kept indefinitely.')
            ->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->daily()->count());
    }

    public function test_monthly_summaries_are_kept_indefinitely_by_default(): void
    {
        $this->summary('month', '2020-01-01');

        $this->artisan('api-usage:prune')
            ->expectsOutputToContain('Monthly summaries are kept indefinitely.')
            ->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->monthly()->count());
    }

    public function test_monthly_summaries_can_be_pruned_when_configured(): void
    {
        config()->set('api_usage.retention.monthly_months', 12);

        $this->summary('month', '2026-05-01');
        $this->summary('month', '2024-01-01');

        $this->artisan('api-usage:prune')->assertExitCode(0);

        $this->assertSame(1, ApiUsageSummary::query()->monthly()->count());
        $this->assertSame('2026-05-01', ApiUsageSummary::query()->monthly()->firstOrFail()->period_start->toDateString());
    }

    public function test_the_raw_window_can_be_overridden_on_the_command_line(): void
    {
        config()->set('api_usage.retention.raw_days', 90);

        $this->request('2026-06-01 12:00:00');

        $this->artisan('api-usage:prune', ['--raw-days' => 5])->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
    }

    public function test_it_is_safe_to_run_repeatedly(): void
    {
        config()->set('api_usage.retention.raw_days', 30);

        $this->request('2026-01-01 12:00:00');
        $this->request('2026-06-16 12:00:00');

        $this->artisan('api-usage:prune')->assertExitCode(0);
        $this->artisan('api-usage:prune')->assertExitCode(0);
        $this->artisan('api-usage:prune')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
    }

    public function test_it_deletes_a_large_backlog_across_several_chunks(): void
    {
        config()->set('api_usage.retention.raw_days', 30);

        for ($i = 0; $i < 12; $i++) {
            $this->request('2026-01-01 12:00:00');
        }

        $this->artisan('api-usage:prune', ['--chunk' => 5])->assertExitCode(0);

        $this->assertSame(0, ApiUsageRequest::query()->count());
    }

    public function test_it_does_nothing_when_the_package_is_disabled(): void
    {
        config()->set('api_usage.enabled', false);

        $this->request('2020-01-01 12:00:00');

        $this->artisan('api-usage:prune')->assertExitCode(0);

        $this->assertSame(1, ApiUsageRequest::query()->count());
    }

    private function request(string $requestedAt): void
    {
        ApiUsageRequest::query()->create([
            'requested_at' => $requestedAt,
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'actor_key' => 'guest',
            'bucket_key' => 'guest',
            'method' => 'GET',
            'path' => '/api/orders',
            'endpoint_key' => 'GET:/api/orders',
            'status_code' => 200,
            'duration_ms' => 10,
        ]);
    }

    private function summary(string $periodType, string $periodStart): void
    {
        ApiUsageSummary::query()->create([
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'actor_type' => 'guest',
            'actor_id' => 'guest',
            'actor_key' => 'guest',
            'bucket_key' => 'guest',
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
            'total_requests' => 1,
        ]);
    }
}
