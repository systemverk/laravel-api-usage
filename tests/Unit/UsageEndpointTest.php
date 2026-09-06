<?php

namespace Systemverk\LaravelApiUsage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;

class UsageEndpointTest extends TestCase
{
    public function test_a_named_route_wins(): void
    {
        $endpoint = UsageEndpoint::make('get', 'api.orders.show', 'api/orders/{order}', '/api/orders/123');

        $this->assertSame('GET:api.orders.show', $endpoint->key());
    }

    public function test_an_unnamed_route_falls_back_to_its_uri(): void
    {
        $endpoint = UsageEndpoint::make('POST', null, 'api/orders/{order}', '/api/orders/123');

        $this->assertSame('POST:/api/orders/{order}', $endpoint->key());
    }

    public function test_a_request_that_matched_no_route_falls_back_to_its_path(): void
    {
        $endpoint = UsageEndpoint::make('DELETE', null, null, 'api/unknown');

        $this->assertSame('DELETE:/api/unknown', $endpoint->key());
    }

    public function test_dynamic_parameters_never_fragment_the_key(): void
    {
        $first = UsageEndpoint::make('GET', null, 'api/orders/{order}', '/api/orders/1');
        $second = UsageEndpoint::make('GET', null, 'api/orders/{order}', '/api/orders/2');

        $this->assertSame($first->key(), $second->key());
        $this->assertNotSame($first->path, $second->path);
    }

    public function test_the_method_is_normalized_but_the_key_still_separates_them(): void
    {
        $this->assertSame('GET', UsageEndpoint::make(' get ', null, null, '/x')->method);
        $this->assertNotSame(
            UsageEndpoint::make('GET', 'api.orders.index', null, '/api/orders')->key(),
            UsageEndpoint::make('POST', 'api.orders.index', null, '/api/orders')->key(),
        );
    }

    public function test_paths_and_route_uris_get_a_leading_slash(): void
    {
        $endpoint = UsageEndpoint::make('GET', null, 'api/orders', 'api/orders');

        $this->assertSame('/api/orders', $endpoint->routeUri);
        $this->assertSame('/api/orders', $endpoint->path);
    }

    public function test_empty_route_information_is_treated_as_absent(): void
    {
        $endpoint = UsageEndpoint::make('GET', '', '', '/api/orders');

        $this->assertNull($endpoint->routeName);
        $this->assertNull($endpoint->routeUri);
        $this->assertSame('GET:/api/orders', $endpoint->key());
    }

    public function test_oversized_values_are_truncated_to_the_column_widths(): void
    {
        $endpoint = UsageEndpoint::make(
            'GET',
            str_repeat('n', 500),
            str_repeat('u', 500),
            str_repeat('p', 4000),
        );

        $this->assertSame(UsageEndpoint::MAX_ROUTE_NAME_LENGTH, mb_strlen((string) $endpoint->routeName));
        $this->assertSame(UsageEndpoint::MAX_ROUTE_URI_LENGTH, mb_strlen((string) $endpoint->routeUri));
        $this->assertSame(UsageEndpoint::MAX_PATH_LENGTH, mb_strlen($endpoint->path));
        $this->assertLessThanOrEqual(UsageEndpoint::MAX_KEY_LENGTH, mb_strlen($endpoint->key()));
    }
}
