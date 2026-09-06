<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\Kernel;
use Systemverk\LaravelApiUsage\Http\Middleware\RecordApiUsage;
use Systemverk\LaravelApiUsage\Tests\TestCase;

/**
 * Kernel::appendMiddlewareToGroup() throws for an unknown group, so an
 * application whose target group does not exist — one that never configured API
 * routing, for instance — must still boot cleanly.
 */
class MissingApiMiddlewareGroupTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        /** @var Repository $config */
        $config = $app['config'];
        $config->set('api_usage.middleware.group', 'does-not-exist');
    }

    public function test_the_application_boots_and_registers_nothing(): void
    {
        /** @var Kernel $kernel */
        $kernel = $this->app()->make(Kernel::class);

        $this->assertTrue($this->app()->isBooted());
        $this->assertArrayNotHasKey('does-not-exist', $kernel->getMiddlewareGroups());

        foreach ($kernel->getMiddlewareGroups() as $middleware) {
            $this->assertNotContains(RecordApiUsage::class, $middleware);
        }
    }
}
