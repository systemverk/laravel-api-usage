<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\Kernel;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Tests\TestCase;

/**
 * Boots the package with both auto-registration switches turned off, which is
 * the "manual wiring" path documented in the README.
 */
class OptionalAutoRegistrationTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var Repository $config */
        $config = $app['config'];

        $config->set('api_usage.schedule.enabled', false);
        $config->set('api_usage.middleware.auto_register', false);
    }

    public function test_no_scheduled_commands_are_registered(): void
    {
        $matching = collect($this->app()->make(Schedule::class)->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'api-usage:'));

        $this->assertCount(0, $matching);
    }

    public function test_the_middleware_is_not_appended_to_the_api_group(): void
    {
        $groups = $this->app()->make(Kernel::class)->getMiddlewareGroups();

        $this->assertNotContains(RecordApiUsage::class, $groups['api'] ?? []);
    }

    public function test_the_commands_are_still_available_for_manual_scheduling(): void
    {
        $commands = array_keys($this->app()->make(\Illuminate\Contracts\Console\Kernel::class)->all());

        $this->assertContains('api-usage:flush', $commands);
    }
}
