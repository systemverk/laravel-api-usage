<?php

namespace Systemverk\LaravelApiUsage\Contracts;

use Illuminate\Http\Request;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;

interface ResolvesUsageEndpoint
{
    /**
     * Describe the logical endpoint a request was made against.
     *
     * Unlike actor resolution this never returns null: every recorded request
     * belongs to some endpoint, even if only its raw path is known. Exceptions
     * are caught and logged by the package, which then falls back to the raw
     * path rather than dropping the request.
     */
    public function resolve(Request $request): UsageEndpoint;
}
