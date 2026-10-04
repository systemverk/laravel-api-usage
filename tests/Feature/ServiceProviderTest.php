<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiUsage\ApiUsageManager;
use Systemverk\LaravelApiUsage\ApiUsageServiceProvider;
use Systemverk\LaravelApiUsage\Actors\AuthenticatedUserActorResolver;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Endpoints\RouteEndpointResolver;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Facades\ApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Tests\TestCase;
use Systemverk\LaravelApiUsage\Usage\ActorQuery;
use Systemverk\LaravelApiUsage\Usage\EndpointQuery;
use Systemverk\LaravelApiUsage\Usage\UsageQuery;

class ServiceProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_registers_every_console_command(): void
    {
        $commands = array_keys($this->app()->make(\Illuminate\Contracts\Console\Kernel::class)->all());

        $this->assertContains('api-usage:flush', $commands);
        $this->assertContains('api-usage:consolidate-daily', $commands);
        $this->assertContains('api-usage:consolidate-monthly', $commands);
        $this->assertContains('api-usage:prune', $commands);
        $this->assertContains('api-usage:status', $commands);
    }

    public function test_it_registers_the_scheduled_commands(): void
    {
        $matching = collect($this->app()->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'api-usage:'));

        // flush, consolidate-daily --today, consolidate-daily, monthly, prune
        $this->assertCount(5, $matching);
        $this->assertTrue($matching->contains(fn ($event) => str_contains((string) $event->command, 'api-usage:flush')));
        $this->assertTrue($matching->contains(fn ($event) => str_contains((string) $event->command, '--today')));
    }

    /**
     * withoutOverlapping() defaults to a 24-hour lock. For a daily command that
     * means a lock left by a killed run is still held when the next run starts,
     * so every guard needs an expiry shorter than the gap between its runs.
     */
    public function test_every_overlap_guard_expires_before_the_next_run(): void
    {
        $events = collect($this->app()->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'api-usage:'));

        foreach ($events as $event) {
            $command = (string) $event->command;

            if (str_contains($command, 'api-usage:flush')) {
                $this->assertFalse($event->withoutOverlapping, 'Flush is guarded by its own claim locks.');

                continue;
            }

            $this->assertTrue($event->withoutOverlapping, "{$command} should not overlap itself.");

            $hourly = str_contains($command, '--today');

            $this->assertLessThan(
                $hourly ? 60 : 1440,
                $event->expiresAt,
                "{$command} must release a stale lock before its next run."
            );
        }
    }

    public function test_the_default_resolvers_are_bound(): void
    {
        $this->assertInstanceOf(AuthenticatedUserActorResolver::class, $this->app()->make(ResolvesUsageActor::class));
        $this->assertInstanceOf(RouteEndpointResolver::class, $this->app()->make(ResolvesUsageEndpoint::class));
    }

    public function test_the_manager_is_a_singleton_reachable_through_the_facade(): void
    {
        $this->assertSame($this->app()->make(ApiUsageManager::class), $this->app()->make(ApiUsageManager::class));

        $this->assertInstanceOf(UsageQuery::class, ApiUsage::usage());
        $this->assertInstanceOf(EndpointQuery::class, ApiUsage::endpoints());
        $this->assertInstanceOf(ActorQuery::class, ApiUsage::actors());
    }

    public function test_every_query_call_returns_a_fresh_builder(): void
    {
        $this->assertNotSame(ApiUsage::usage(), ApiUsage::usage());
    }

    public function test_the_migrations_create_both_tables(): void
    {
        $this->assertTrue(Schema::hasTable('api_usage_requests'));
        $this->assertTrue(Schema::hasTable('api_usage_summaries'));

        $this->assertTrue(Schema::hasColumns('api_usage_requests', [
            'actor_type', 'actor_id', 'credential_id',
            'method', 'route_name', 'route_uri', 'path', 'endpoint_key',
            'status_code', 'duration_ms', 'ip_hash', 'user_agent', 'request_id',
        ]));

        $this->assertTrue(Schema::hasColumns('api_usage_summaries', [
            'period_type', 'period_start', 'actor_type', 'actor_id',
            'credential_id', 'endpoint_key', 'method', 'route_name', 'route_uri',
            'responses_1xx', 'responses_2xx', 'responses_3xx', 'responses_4xx', 'responses_5xx',
            'total_duration_ms', 'min_duration_ms', 'max_duration_ms',
        ]));
    }

    public function test_the_v1_user_id_column_is_gone(): void
    {
        $this->assertFalse(Schema::hasColumn('api_usage_requests', 'user_id'));
        $this->assertFalse(Schema::hasColumn('api_usage_summaries', 'user_id'));
    }

    public function test_the_aggregation_identity_is_unique(): void
    {
        $row = [
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_type' => 'user',
            'actor_id' => '1',
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
            'total_requests' => 1,
        ];

        ApiUsageSummary::query()->create($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ApiUsageSummary::query()->create($row);
    }

    /**
     * Every column of the unique index at its widest. On MySQL this is the case
     * that would fail first if the index outgrew the engine's key length limit.
     */
    public function test_a_full_width_identity_survives_a_round_trip(): void
    {
        ApiUsageSummary::query()->create([
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_type' => str_repeat('x', UsageActor::MAX_TYPE_LENGTH),
            'actor_id' => str_repeat('y', UsageActor::MAX_ID_LENGTH),
            'credential_id' => str_repeat('z', UsageEvent::MAX_CREDENTIAL_ID_LENGTH),
            'endpoint_key' => str_repeat('w', UsageEndpoint::MAX_KEY_LENGTH),
            'method' => 'GET',
            'total_requests' => 1,
        ]);

        $summary = ApiUsageSummary::query()->firstOrFail();

        $this->assertSame(str_repeat('y', UsageActor::MAX_ID_LENGTH), $summary->actor_id);
        $this->assertSame(str_repeat('z', UsageEvent::MAX_CREDENTIAL_ID_LENGTH), $summary->credential_id);
    }

    public function test_no_credential_is_stored_as_an_empty_string_and_read_back_as_null(): void
    {
        ApiUsageSummary::query()->create([
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_type' => 'user',
            'actor_id' => '1',
            'credential_id' => null,
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
        ]);

        $this->assertNull(ApiUsageSummary::query()->firstOrFail()->credential_id);
        $this->assertSame('', ApiUsageSummary::query()->toBase()->value('credential_id'));
    }

    /**
     * NULL is distinct from every other NULL in a unique index, which is why a
     * missing credential is stored as an empty string instead.
     */
    public function test_two_rows_without_a_credential_still_collide(): void
    {
        $row = [
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_type' => 'user',
            'actor_id' => '1',
            'credential_id' => null,
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
        ];

        ApiUsageSummary::query()->create($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ApiUsageSummary::query()->create($row);
    }

    public function test_table_names_are_configurable(): void
    {
        config()->set('api_usage.database.tables.requests', 'tenant_usage_requests');
        config()->set('api_usage.database.tables.summaries', 'tenant_usage_summaries');

        $this->assertSame('tenant_usage_requests', (new ApiUsageRequest)->getTable());
        $this->assertSame('tenant_usage_summaries', (new ApiUsageSummary)->getTable());
    }

    public function test_the_database_connection_is_configurable(): void
    {
        $this->assertNull((new ApiUsageRequest)->getConnectionName());

        config()->set('api_usage.database.connection', 'usage');

        $this->assertSame('usage', (new ApiUsageRequest)->getConnectionName());
        $this->assertSame('usage', (new ApiUsageSummary)->getConnectionName());
    }

    public function test_the_config_file_is_publishable(): void
    {
        $paths = \Illuminate\Support\ServiceProvider::pathsToPublish(
            ApiUsageServiceProvider::class,
            'api-usage-config'
        );

        $this->assertNotEmpty($paths);
        $this->assertStringEndsWith('api_usage.php', (string) array_key_first($paths));
    }
}
