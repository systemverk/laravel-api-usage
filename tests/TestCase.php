<?php

namespace Systemverk\LaravelApiUsage\Tests;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;
use Systemverk\LaravelApiUsage\ApiUsageServiceProvider;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;
use Systemverk\LaravelApiUsage\Tests\Support\FakeRedisConnection;

abstract class TestCase extends Orchestra
{
    protected function tearDown(): void
    {
        // The credential resolver is static, so it would otherwise leak between
        // tests.
        UsageRecorder::resolveCredentialUsing(null);

        parent::tearDown();
    }

    /**
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ApiUsageServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Repository $config */
        $config = $app['config'];

        $config->set('database.default', 'testing');
        $config->set('database.connections.testing', $this->databaseConnection());

        $config->set('database.redis.default', [
            'host' => '127.0.0.1',
            'port' => 6379,
            'database' => 0,
        ]);
    }

    /**
     * In-memory SQLite unless TEST_DB_DRIVER says otherwise. CI runs the whole
     * suite against MySQL and PostgreSQL as well, because the consolidation
     * SQL and the width of the unique index are exactly what SQLite cannot
     * vouch for.
     *
     * @return array<string, mixed>
     */
    private function databaseConnection(): array
    {
        $driver = (string) (getenv('TEST_DB_DRIVER') ?: 'sqlite');

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver' => $driver,
            'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
            'port' => getenv('TEST_DB_PORT') ?: ($driver === 'pgsql' ? '5432' : '3306'),
            'database' => getenv('TEST_DB_DATABASE') ?: 'api_usage_test',
            'username' => getenv('TEST_DB_USERNAME') ?: ($driver === 'pgsql' ? 'postgres' : 'root'),
            'password' => getenv('TEST_DB_PASSWORD') ?: 'secret',
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => $driver === 'pgsql' ? null : 'utf8mb4_unicode_ci',
            'prefix' => '',
        ];
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->artisan('migrate')->run();
    }

    /**
     * Swap the Redis facade for an in-memory double and return it.
     */
    protected function fakeRedis(): FakeRedisConnection
    {
        $fake = new FakeRedisConnection;

        Redis::shouldReceive('connection')->andReturn($fake);

        return $fake;
    }

    /**
     * @return \Illuminate\Foundation\Application
     */
    protected function app(): Application
    {
        /** @var Application $app */
        $app = $this->app;

        return $app;
    }
}
