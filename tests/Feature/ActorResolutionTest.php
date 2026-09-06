<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use RuntimeException;
use Systemverk\LaravelApiUsage\Actors\AuthenticatedUserActorResolver;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Facades\ApiUsage;
use Systemverk\LaravelApiUsage\Tests\TestCase;

class ActorResolutionTest extends TestCase
{
    public function test_the_default_resolver_attributes_usage_to_the_authenticated_user(): void
    {
        $request = $this->requestAuthenticatedAs(42);

        $actor = (new AuthenticatedUserActorResolver)->resolve($request);

        $this->assertSame('user:42', $actor?->key());
    }

    public function test_it_uses_the_auth_identifier_rather_than_an_id_property(): void
    {
        $user = new class implements Authenticatable
        {
            public string $id = 'the-wrong-one';

            public function getAuthIdentifierName(): string
            {
                return 'uuid';
            }

            public function getAuthIdentifier(): string
            {
                return 'the-right-one';
            }

            public function getAuthPasswordName(): string
            {
                return 'password';
            }

            public function getAuthPassword(): string
            {
                return '';
            }

            public function getRememberToken(): string
            {
                return '';
            }

            public function setRememberToken($value): void {}

            public function getRememberTokenName(): string
            {
                return '';
            }
        };

        $request = Request::create('/api/orders');
        $request->setUserResolver(fn () => $user);

        $this->assertSame('user:the-right-one', (new AuthenticatedUserActorResolver)->resolve($request)?->key());
    }

    public function test_a_non_integer_identifier_is_preserved(): void
    {
        $request = $this->requestAuthenticatedAs('01JZ8YFB6M6ZQ4Q0V9F2P3T7XK');

        $this->assertSame(
            'user:01JZ8YFB6M6ZQ4Q0V9F2P3T7XK',
            (new AuthenticatedUserActorResolver)->resolve($request)?->key()
        );
    }

    public function test_an_unauthenticated_request_becomes_the_guest_actor(): void
    {
        $actor = (new AuthenticatedUserActorResolver)->resolve(Request::create('/api/orders'));

        $this->assertTrue($actor?->isGuest());
    }

    public function test_guest_tracking_can_be_turned_off(): void
    {
        config()->set('api_usage.actor.track_guests', false);

        $this->assertNull((new AuthenticatedUserActorResolver)->resolve(Request::create('/api/orders')));
    }

    public function test_turning_off_guest_tracking_does_not_affect_authenticated_requests(): void
    {
        config()->set('api_usage.actor.track_guests', false);

        $actor = (new AuthenticatedUserActorResolver)->resolve($this->requestAuthenticatedAs(9));

        $this->assertSame('user:9', $actor?->key());
    }

    public function test_a_custom_resolver_can_be_configured(): void
    {
        config()->set('api_usage.actor.resolver', OrganizationActorResolver::class);

        $this->assertSame('organization:7', ApiUsage::actorFor(Request::create('/api/orders'))?->key());
    }

    public function test_a_custom_resolver_may_decline_to_attribute_a_request(): void
    {
        config()->set('api_usage.actor.resolver', OrganizationActorResolver::class);

        $request = Request::create('/api/orders');
        $request->headers->set('X-Organization-Id', '');

        $this->assertNull(ApiUsage::actorFor($request));
    }

    public function test_the_resolver_is_built_through_the_container(): void
    {
        config()->set('api_usage.actor.resolver', InjectedActorResolver::class);

        $this->app()->instance(ActorDependency::class, new ActorDependency('tenant-from-container'));

        $this->assertSame('tenant:tenant-from-container', ApiUsage::actorFor(Request::create('/api/orders'))?->key());
    }

    public function test_a_resolver_that_does_not_implement_the_contract_is_rejected(): void
    {
        config()->set('api_usage.actor.resolver', \stdClass::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must implement');

        $this->app()->make(ResolvesUsageActor::class);
    }

    private function requestAuthenticatedAs(string|int $identifier): Request
    {
        $request = Request::create('/api/orders');
        $request->setUserResolver(fn () => new class($identifier)
        {
            public function __construct(private readonly string|int $identifier) {}

            public function getAuthIdentifier(): string|int
            {
                return $this->identifier;
            }
        });

        return $request;
    }
}

/**
 * The resolver shape documented in the README: attribute usage to the caller's
 * organization, and record nothing at all when there is none.
 */
final class OrganizationActorResolver implements ResolvesUsageActor
{
    public function resolve(Request $request): ?UsageActor
    {
        $organizationId = $request->headers->get('X-Organization-Id', '7');

        return $organizationId === '' ? null : UsageActor::organization($organizationId);
    }
}

final class ActorDependency
{
    public function __construct(public readonly string $tenant) {}
}

final class InjectedActorResolver implements ResolvesUsageActor
{
    public function __construct(private readonly ActorDependency $dependency) {}

    public function resolve(Request $request): ?UsageActor
    {
        return $this->dependency->tenant === ''
            ? null
            : UsageActor::make('tenant', $this->dependency->tenant);
    }
}
