<?php

namespace Systemverk\LaravelApiUsage\Tests\Integration;

class PhpRedisIntegrationTest extends RedisIntegrationTestCase
{
    protected function client(): string
    {
        return 'phpredis';
    }
}
