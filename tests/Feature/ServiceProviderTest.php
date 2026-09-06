<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiUsage\ApiUsageManager;
use Systemverk\LaravelApiUsage\ApiUsageServiceProvider;
use Systemverk\LaravelApiUsage\Actors\AuthenticatedUserActorResolver;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Endpoints\RouteEndpointResolver;
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
            'actor_type', 'actor_id', 'actor_key', 'credential_id', 'bucket_key',
            'method', 'route_name', 'route_uri', 'path', 'endpoint_key',
            'status_code', 'duration_ms', 'ip_hash', 'user_agent', 'request_id',
        ]));

        $this->assertTrue(Schema::hasColumns('api_usage_summaries', [
            'period_type', 'period_start', 'actor_type', 'actor_id', 'actor_key',
            'credential_id', 'bucket_key', 'endpoint_key', 'method', 'route_name', 'route_uri',
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
            'actor_key' => 'user:1',
            'bucket_key' => 'user:1',
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
            'total_requests' => 1,
        ];

        ApiUsageSummary::query()->create($row);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        ApiUsageSummary::query()->create($row);
    }

    public function test_a_full_width_bucket_key_survives_a_round_trip(): void
    {
        // "user:" + 64 + "|cred:" + 64 = 139 characters at worst.
        $bucketKey = 'user:'.str_repeat('x', 64).'|cred:'.str_repeat('y', 64);

        ApiUsageSummary::query()->create([
            'period_type' => 'day',
            'period_start' => '2026-06-17',
            'actor_key' => 'user:'.str_repeat('x', 64),
            'bucket_key' => $bucketKey,
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
            'total_requests' => 1,
        ]);

        $this->assertSame($bucketKey, ApiUsageSummary::query()->value('bucket_key'));
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
