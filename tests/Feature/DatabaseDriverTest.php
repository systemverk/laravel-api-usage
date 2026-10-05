<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Storage\DatabaseWriter;
use Systemverk\LaravelApiUsage\Storage\RedisBuffer;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class DatabaseDriverTest extends TestCase
{
    use RefreshDatabase;

    public function test_redis_is_the_default_driver(): void
    {
        $this->assertInstanceOf(RedisBuffer::class, $this->app()->make(StoresUsageEvents::class));
    }

    public function test_the_database_driver_is_selected_by_config(): void
    {
        config()->set('api_usage.driver', 'database');

        $this->assertInstanceOf(DatabaseWriter::class, $this->app()->make(StoresUsageEvents::class));
    }

    public function test_an_unknown_driver_falls_back_to_redis(): void
    {
        config()->set('api_usage.driver', 'carrier-pigeon');

        $this->assertInstanceOf(RedisBuffer::class, $this->app()->make(StoresUsageEvents::class));
    }

    public function test_a_request_is_written_straight_to_the_requests_table(): void
    {
        config()->set('api_usage.driver', 'database');
        Redis::shouldReceive('connection')->never();

        $request = Request::create('/api/orders/7?include=items');
        $request->headers->set('User-Agent', 'curl/8.0');

        $this->runMiddleware($request, new Response('ok', 201));

        $row = ApiUsageRequest::query()->firstOrFail();

        $this->assertSame('guest', $row->actor_type);
        $this->assertSame('guest', $row->actor_id);
        $this->assertSame('GET', $row->method);
        $this->assertSame('/api/orders/7', $row->path, 'The query string is never stored.');
        $this->assertSame('GET:/{unmatched}', $row->endpoint_key);
        $this->assertSame(201, $row->status_code);
        $this->assertSame('curl/8.0', $row->user_agent);
        $this->assertNotNull($row->ip_hash);
    }

    public function test_a_database_failure_is_logged_and_swallowed(): void
    {
        config()->set('api_usage.driver', 'database');
        config()->set('api_usage.database.tables.requests', 'a_table_that_does_not_exist');

        Log::shouldReceive('warning')
            ->once()
            ->withArgs(fn (string $message) => str_contains($message, 'Failed to record'));

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        // Reaching this line is the assertion: a failed write must not fail the request.
        $this->addToAssertionCount(1);
    }

    public function test_it_records_nothing_when_the_actor_resolver_opts_out(): void
    {
        config()->set('api_usage.driver', 'database');
        config()->set('api_usage.actor.track_guests', false);

        $this->runMiddleware(Request::create('/api/orders'), new Response);

        $this->assertSame(0, ApiUsageRequest::query()->count());
    }

    public function test_flush_has_nothing_to_do_and_never_touches_redis(): void
    {
        config()->set('api_usage.driver', 'database');
        Redis::shouldReceive('connection')->never();

        $this->artisan('api-usage:flush')
            ->expectsOutputToContain('nothing to flush')
            ->assertExitCode(0);
    }

    public function test_the_flush_command_is_not_scheduled(): void
    {
        config()->set('api_usage.driver', 'database');

        $commands = collect($this->freshSchedule()->events())->map(fn ($event) => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $c) => str_contains($c, 'api-usage:consolidate-daily')));
        $this->assertFalse($commands->contains(fn (string $c) => str_contains($c, 'api-usage:flush')));
    }

    public function test_the_flush_command_is_scheduled_for_the_redis_driver(): void
    {
        $commands = collect($this->freshSchedule()->events())->map(fn ($event) => (string) $event->command);

        $this->assertTrue($commands->contains(fn (string $c) => str_contains($c, 'api-usage:flush')));
    }

    public function test_status_reports_the_driver_and_skips_redis(): void
    {
        config()->set('api_usage.driver', 'database');
        Redis::shouldReceive('connection')->never();

        $this->artisan('api-usage:status')
            ->expectsOutputToContain('database')
            ->expectsOutputToContain('not used')
            ->assertExitCode(0);
    }

    /**
     * The schedule is built when the container first resolves it, so a driver
     * set afterwards needs a fresh application to be seen.
     */
    private function freshSchedule(): Schedule
    {
        $this->app()->forgetInstance(Schedule::class);

        return $this->app()->make(Schedule::class);
    }

    private function runMiddleware(Request $request, Response $response): void
    {
        $middleware = $this->app()->make(RecordApiUsage::class);
        $middleware->handle($request, fn () => $response);
        $middleware->terminate($request, $response);
    }
}
