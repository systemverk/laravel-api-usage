<?php

namespace Systemverk\LaravelApiUsage\Endpoints;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * Resolve the endpoint from Laravel's own routing information.
 */
class RouteEndpointResolver implements ResolvesUsageEndpoint
{
    /**
     * Stands in for the route URI of every request that matched no route.
     */
    public const UNMATCHED_URI = '/{unmatched}';

    public function resolve(Request $request): UsageEndpoint
    {
        $route = $request->route();

        // A request rejected before routing — a 404, or a middleware that
        // returned early — has no route at all, and only the path is known.
        $route = $route instanceof Route ? $route : null;

        // Keyed by path, every scanner probe would become an endpoint of its
        // own. Collapsing them keeps the summaries bounded, while the raw rows
        // still carry the real path.
        if ($route === null && UsageConfig::collapseUnmatchedEndpoints()) {
            return UsageEndpoint::make($request->method(), null, self::UNMATCHED_URI, $request->path());
        }

        return UsageEndpoint::make(
            $request->method(),
            $route?->getName(),
            $route?->uri(),
            $request->path(),
        );
    }
}
