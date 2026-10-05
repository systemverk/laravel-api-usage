<?php

namespace Systemverk\LaravelApiUsage;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel as FoundationHttpKernel;
use Illuminate\Support\ServiceProvider;
use RuntimeException;
use Systemverk\LaravelApiUsage\Console\Commands\ApiUsageStatus;
use Systemverk\LaravelApiUsage\Console\Commands\ConsolidateDailyApiUsage;
use Systemverk\LaravelApiUsage\Console\Commands\ConsolidateMonthlyApiUsage;
use Systemverk\LaravelApiUsage\Console\Commands\FlushApiUsage;
use Systemverk\LaravelApiUsage\Console\Commands\PruneApiUsage;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Storage\DatabaseWriter;
use Systemverk\LaravelApiUsage\Storage\RedisBuffer;
use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

class ApiUsageServiceProvider extends ServiceProvider
{
    /**
     * How long the overlap guard of the hourly consolidation may be held: well
     * under the hour between runs, so a stale lock never skips the next one.
     */
    private const HOURLY_MUTEX_MINUTES = 50;

    /**
     * The same for the daily, monthly and prune runs: far longer than any
     * healthy run, yet under the 24 hours between daily runs.
     */
    private const LONG_MUTEX_MINUTES = 720;

    /**
     * Register package services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/api_usage.php', 'api_usage');

        $this->app->singleton(ApiUsageManager::class);

        // The recorder is stateless, and the middleware receives it by
        // injection, so one instance per worker is enough.
        $this->app->singleton(UsageRecorder::class);

        // Chosen when the middleware is built, not when the provider
        // registers, so the driver follows the config as it stands.
        $this->app->bind(
            StoresUsageEvents::class,
            fn () => UsageConfig::usesRedis() ? new RedisBuffer : new DatabaseWriter
        );

        $this->bindResolver(ResolvesUsageActor::class, UsageConfig::actorResolver(...));
        $this->bindResolver(ResolvesUsageEndpoint::class, UsageConfig::endpointResolver(...));
    }

    /**
     * Bootstrap package services.
     */
    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->commands([
                FlushApiUsage::class,
                ConsolidateDailyApiUsage::class,
                ConsolidateMonthlyApiUsage::class,
                PruneApiUsage::class,
                ApiUsageStatus::class,
            ]);

            $this->publishes([
                __DIR__.'/../config/api_usage.php' => config_path('api_usage.php'),
            ], 'api-usage-config');
        }

        $this->registerMiddleware();
        $this->registerSchedule();
    }

    /**
     * Resolve a configured resolver class through the container, so custom
     * implementations may use constructor injection.
     *
     * The class name is read lazily: the config file is not necessarily merged
     * yet when register() runs on a cached-config application.
     *
     * @param  class-string  $contract
     * @param  \Closure(): class-string  $configured
     */
    private function bindResolver(string $contract, \Closure $configured): void
    {
        $this->app->bind($contract, function ($app) use ($contract, $configured) {
            $class = $configured();
            $resolver = $app->make($class);

            if (! $resolver instanceof $contract) {
                throw new RuntimeException("[{$class}] must implement [{$contract}].");
            }

            return $resolver;
        });
    }

    /**
     * Append the usage recording middleware to the "api" group.
     */
    private function registerMiddleware(): void
    {
        if (! config('api_usage.middleware.auto_register', true)) {
            return;
        }

        // Deliberately not guarded by runningInConsole(): Octane workers run
        // under the CLI SAPI and still serve HTTP requests.
        if (! $this->app->bound(HttpKernel::class)) {
            return;
        }

        $group = (string) config('api_usage.middleware.group', 'api');

        // bootstrap/app.php applies withMiddleware() through afterResolving on
        // the kernel, replacing the group list wholesale. Hooking the same event
        // — rather than app->booted() — guarantees we append after that, not
        // before, so our entry survives.
        $this->callAfterResolving(HttpKernel::class, function ($kernel) use ($group): void {
            // appendMiddlewareToGroup lives on the concrete kernel, not the
            // contract, so a custom kernel implementation is simply skipped.
            if (! $kernel instanceof FoundationHttpKernel) {
                return;
            }

            // The method throws for an unknown group, and an application that
            // never called withRouting(api: ...) has no "api" group at all.
            if (! array_key_exists($group, $kernel->getMiddlewareGroups())) {
                return;
            }

            $kernel->appendMiddlewareToGroup($group, RecordApiUsage::class);
        });
    }

    /**
     * Register the flush, consolidation and prune commands on the scheduler.
     *
     * callAfterResolving means the scheduler is only touched if the application
     * actually builds one, so nothing is resolved during a normal web request.
     */
    private function registerSchedule(): void
    {
        if (! config('api_usage.schedule.enabled', true)) {
            return;
        }

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            // Only the Redis driver has anything to flush. Deliberately not
            // withoutOverlapping(): its mutex lasts 24 hours by default, so one
            // killed run (a deploy, an OOM) would stop flushing for a day.
            // Overlap is harmless here, because every buffer is claimed
            // atomically and guarded by its own expiring lock.
            if (UsageConfig::usesRedis()) {
                $schedule->command(FlushApiUsage::class)->everyMinute();
            }

            // Every guard below carries an explicit expiry. withoutOverlapping()
            // defaults to 24 hours, and a lock left behind by a killed run would
            // then still be held when the next daily run starts at the same
            // time of day, silently skipping it. The expiry has to be shorter
            // than the interval between runs.

            // The query API reads summaries, so today's numbers are only as
            // fresh as the most recent consolidation of the current day.
            if (config('api_usage.schedule.consolidate_today', true)) {
                $schedule->command(ConsolidateDailyApiUsage::class, ['--today'])
                    ->hourly()
                    ->withoutOverlapping(self::HOURLY_MUTEX_MINUTES);
            }

            // Yesterday and the day before: events flushed after a backlog or a
            // recovery land in an earlier day, and the nightly run is the one
            // that picks them up.
            $schedule->command(ConsolidateDailyApiUsage::class, ['--days=2'])
                ->dailyAt((string) config('api_usage.schedule.daily_at', '02:00'))
                ->withoutOverlapping(self::LONG_MUTEX_MINUTES);

            $schedule->command(ConsolidateMonthlyApiUsage::class)
                ->monthlyOn(1, (string) config('api_usage.schedule.monthly_at', '03:00'))
                ->withoutOverlapping(self::LONG_MUTEX_MINUTES);

            $schedule->command(PruneApiUsage::class)
                ->dailyAt((string) config('api_usage.schedule.prune_at', '03:10'))
                ->withoutOverlapping(self::LONG_MUTEX_MINUTES);
        });
    }
}
