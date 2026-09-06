<?php

namespace Systemverk\LaravelApiUsage\Endpoints;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;

/**
 * Resolve the endpoint from Laravel's own routing information.
 */
class RouteEndpointResolver implements ResolvesUsageEndpoint
{
    public function resolve(Request $request): UsageEndpoint
    {
        $route = $request->route();

        // A request rejected before routing — a 404, or a middleware that
        // returned early — has no route at all, and only the path is known.
        $route = $route instanceof Route ? $route : null;

        return UsageEndpoint::make(
            $request->method(),
            $route?->getName(),
            $route?->uri(),
            $request->path(),
        );
    }
}
