<?php

namespace Systemverk\LaravelApiUsage\Actors;

use Illuminate\Http\Request;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * The zero-configuration default: attribute usage to the authenticated user.
 *
 * Because Sanctum lets any model be tokenable, this already covers team- and
 * organization-scoped tokens — the "user" is whatever the guard authenticated.
 * Applications that need a different dimension implement
 * {@see ResolvesUsageActor} themselves.
 */
class AuthenticatedUserActorResolver implements ResolvesUsageActor
{
    public function resolve(Request $request): ?UsageActor
    {
        $user = $request->user();

        $identifier = $user !== null && method_exists($user, 'getAuthIdentifier')
            ? $user->getAuthIdentifier()
            : null;

        // Anything non-scalar — or an empty identifier — is treated as "not
        // authenticated" rather than guessed at.
        if (is_scalar($identifier) && trim((string) $identifier) !== '') {
            return UsageActor::user((string) $identifier);
        }

        return UsageConfig::trackGuests() ? UsageActor::guest() : null;
    }
}
