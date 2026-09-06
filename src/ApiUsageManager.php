<?php

namespace Systemverk\LaravelApiUsage;

use Illuminate\Http\Request;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Usage\ActorQuery;
use Systemverk\LaravelApiUsage\Usage\EndpointQuery;
use Systemverk\LaravelApiUsage\Usage\UsageQuery;

/**
 * The package's single entry point, normally reached through the ApiUsage
 * facade. Deliberately thin: it hands out query objects and nothing else.
 */
class ApiUsageManager
{
    /**
     * Usage totals for a period, optionally narrowed to one actor.
     */
    public function usage(): UsageQuery
    {
        return new UsageQuery;
    }

    /**
     * Per-endpoint analytics: most used, slowest, most errors.
     */
    public function endpoints(): EndpointQuery
    {
        return new EndpointQuery;
    }

    /**
     * Per-actor analytics: most active, most errors.
     */
    public function actors(): ActorQuery
    {
        return new ActorQuery;
    }

    /**
     * Ask the configured resolver who a request belongs to.
     *
     * Useful for verifying a custom resolver from a test or tinker session
     * without going through the middleware.
     */
    public function actorFor(Request $request): ?UsageActor
    {
        return app(ResolvesUsageActor::class)->resolve($request);
    }
}
