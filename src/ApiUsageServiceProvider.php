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
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

class ApiUsageServiceProvider extends ServiceProvider
{
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
            $flushMinutes = max(1, (int) config('api_usage.schedule.flush_minutes', 5));

            $schedule->command(FlushApiUsage::class, ["--max-minutes={$flushMinutes}"])
                ->everyMinute()
                ->withoutOverlapping();

            // The query API reads summaries, so today's numbers are only as
            // fresh as the most recent consolidation of the current day.
            if (config('api_usage.schedule.consolidate_today', true)) {
                $schedule->command(ConsolidateDailyApiUsage::class, ['--today'])
                    ->hourly()
                    ->withoutOverlapping();
            }

            $schedule->command(ConsolidateDailyApiUsage::class)
                ->dailyAt((string) config('api_usage.schedule.daily_at', '02:00'))
                ->withoutOverlapping();

            $schedule->command(ConsolidateMonthlyApiUsage::class)
                ->monthlyOn(1, (string) config('api_usage.schedule.monthly_at', '03:00'))
                ->withoutOverlapping();

            $schedule->command(PruneApiUsage::class)
                ->dailyAt((string) config('api_usage.schedule.prune_at', '03:10'))
                ->withoutOverlapping();
        });
    }
}
