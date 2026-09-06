<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Systemverk\LaravelApiUsage\Tests\TestCase;

/**
 * Very high-volume installations may not want the current day recomputed every
 * hour. Turning it off must leave the rest of the schedule intact.
 */
class DisabledTodayConsolidationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var Repository $config */
        $config = $app['config'];
        $config->set('api_usage.schedule.consolidate_today', false);
    }

    public function test_only_the_hourly_run_is_dropped(): void
    {
        $matching = collect($this->app()->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'api-usage:'));

        $this->assertCount(4, $matching);
        $this->assertFalse($matching->contains(fn ($event) => str_contains((string) $event->command, '--today')));
        $this->assertTrue($matching->contains(fn ($event) => str_contains((string) $event->command, 'api-usage:flush')));
    }
}
