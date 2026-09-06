<?php

namespace Systemverk\LaravelApiUsage\Facades;

use Illuminate\Support\Facades\Facade;
use Systemverk\LaravelApiUsage\ApiUsageManager;

/**
 * @method static \Systemverk\LaravelApiUsage\Usage\UsageQuery usage()
 * @method static \Systemverk\LaravelApiUsage\Usage\EndpointQuery endpoints()
 * @method static \Systemverk\LaravelApiUsage\Usage\ActorQuery actors()
 * @method static \Systemverk\LaravelApiUsage\Actors\UsageActor|null actorFor(\Illuminate\Http\Request $request)
 *
 * @see ApiUsageManager
 */
class ApiUsage extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ApiUsageManager::class;
    }
}
