<?php

namespace Systemverk\LaravelApiUsage\Tests\Integration;

class PredisIntegrationTest extends RedisIntegrationTestCase
{
    protected function client(): string
    {
        return 'predis';
    }
}
