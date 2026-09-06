<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Endpoints\RouteEndpointResolver;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class EndpointResolutionTest extends TestCase
{
    protected function defineRoutes($router): void
    {
        $router->get('api/orders/{order}', fn () => response()->json([]))->name('api.orders.show');
        $router->get('api/reports/{report}', fn () => response()->json([]));
    }

    public function test_a_named_route_resolves_to_its_name(): void
    {
        $endpoint = $this->resolveFor('/api/orders/123');

        $this->assertSame('api.orders.show', $endpoint->routeName);
        $this->assertSame('/api/orders/{order}', $endpoint->routeUri);
        $this->assertSame('/api/orders/123', $endpoint->path);
        $this->assertSame('GET:api.orders.show', $endpoint->key());
    }

    public function test_an_unnamed_route_resolves_to_its_uri(): void
    {
        $endpoint = $this->resolveFor('/api/reports/7');

        $this->assertNull($endpoint->routeName);
        $this->assertSame('GET:/api/reports/{report}', $endpoint->key());
    }

    public function test_dynamic_parameters_do_not_fragment_the_endpoint(): void
    {
        $this->assertSame(
            $this->resolveFor('/api/orders/1')->key(),
            $this->resolveFor('/api/orders/2')->key()
        );
    }

    public function test_a_request_outside_routing_falls_back_to_its_path(): void
    {
        $endpoint = (new RouteEndpointResolver)->resolve(Request::create('/api/never-routed'));

        $this->assertNull($endpoint->routeName);
        $this->assertNull($endpoint->routeUri);
        $this->assertSame('GET:/api/never-routed', $endpoint->key());
    }

    public function test_a_custom_endpoint_resolver_can_be_configured(): void
    {
        config()->set('api_usage.endpoint.resolver', ConstantEndpointResolver::class);

        $endpoint = $this->app()->make(ResolvesUsageEndpoint::class)->resolve(Request::create('/anything'));

        $this->assertSame('GET:everything', $endpoint->key());
    }

    private function resolveFor(string $uri): UsageEndpoint
    {
        $request = Request::create($uri);

        // Bind the matched route onto the request, exactly as the router does
        // before the middleware runs.
        $route = Route::getRoutes()->match($request);
        $request->setRouteResolver(fn () => $route);

        return (new RouteEndpointResolver)->resolve($request);
    }
}

final class ConstantEndpointResolver implements ResolvesUsageEndpoint
{
    public function resolve(Request $request): UsageEndpoint
    {
        return UsageEndpoint::make('GET', 'everything', null, '/');
    }
}
