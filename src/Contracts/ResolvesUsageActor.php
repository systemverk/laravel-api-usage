<?php

namespace Systemverk\LaravelApiUsage\Contracts;

use Illuminate\Http\Request;
use Systemverk\LaravelApiUsage\Actors\UsageActor;

interface ResolvesUsageActor
{
    /**
     * Decide who a request should be attributed to.
     *
     * Return a {@see UsageActor} to record the request against that actor, or
     * null to skip recording it entirely — that is how guest traffic is dropped
     * when guest tracking is disabled.
     *
     * Implementations run after the response has been sent. They must not
     * assume a session, must not write to the database, and should be cheap.
     * Exceptions are caught and logged by the package: the request is simply
     * not recorded, and the application is never affected.
     */
    public function resolve(Request $request): ?UsageActor;
}
