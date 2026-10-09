<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class UsageTimezoneTest extends TestCase
{
    public function test_it_follows_the_application_timezone(): void
    {
        config()->set('app.timezone', 'Europe/Oslo');

        $this->assertSame('Europe/Oslo', UsageConfig::timezone());
        $this->assertSame('Europe/Oslo', UsageConfig::now()->getTimezone()->getName());
    }

    public function test_it_falls_back_to_utc_when_the_zone_is_missing_or_unknown(): void
    {
        foreach ([null, '', 'Mars/Olympus', 42] as $timezone) {
            config()->set('app.timezone', $timezone);

            $this->assertSame('UTC', UsageConfig::timezone());
        }
    }
}
