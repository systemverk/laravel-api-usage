<?php

use Systemverk\LaravelApiUsage\Actors\AuthenticatedUserActorResolver;
use Systemverk\LaravelApiUsage\Endpoints\RouteEndpointResolver;

return [

    /*
    |--------------------------------------------------------------------------
    | Master switch
    |--------------------------------------------------------------------------
    |
    | When disabled, the middleware records nothing and every scheduled command
    | exits immediately without touching Redis or the database.
    |
    */

    'enabled' => env('API_USAGE_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Actor
    |--------------------------------------------------------------------------
    |
    | An actor is whoever usage is attributed to: a user, an organization, a
    | tenant, an API key, a service account. The resolver decides which, and is
    | resolved through the container, so it may use constructor injection.
    |
    | Returning null from a resolver means "do not record this request".
    |
    | "credential_resolver" is a second, orthogonal dimension: which key the
    | actor used. It answers "which of this team's tokens made the call" while
    | the actor stays the team. With Laravel Sanctum:
    |
    |     'credential_resolver' => fn ($request) => $request->user()?->currentAccessToken()?->getKey(),
    |
    | A closure here makes the config file uncacheable. Prefer registering it
    | from a service provider instead:
    |
    |     UsageRecorder::resolveCredentialUsing(
    |         fn ($request) => $request->user()?->currentAccessToken()?->getKey()
    |     );
    |
    | A resolver registered in code takes precedence over one configured here.
    |
    */

    'actor' => [
        'resolver' => AuthenticatedUserActorResolver::class,

        'track_guests' => env('API_USAGE_TRACK_GUESTS', true),

        'credential_resolver' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Endpoint
    |--------------------------------------------------------------------------
    |
    | Requests are grouped by a canonical endpoint identity rather than by their
    | concrete path, so /orders/1 and /orders/2 aggregate together. The key is
    | "METHOD:route_name", falling back to "METHOD:route_uri" and finally
    | "METHOD:/path" for requests that never matched a route.
    |
    */

    'endpoint' => [
        'resolver' => RouteEndpointResolver::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis buffer
    |--------------------------------------------------------------------------
    |
    | Requests are appended to a per-minute Redis list after the response has
    | been sent, and flushed to SQL by the "api-usage:flush" command. The TTL is
    | a safety net: it must comfortably exceed the flush interval.
    |
    */

    'buffer' => [
        'connection' => env('API_USAGE_REDIS_CONNECTION', 'default'),
        'key_prefix' => env('API_USAGE_REDIS_KEY_PREFIX', 'api_usage:'),
        'ttl_seconds' => (int) env('API_USAGE_REDIS_TTL_SECONDS', 7200),
        'flush_batch_size' => (int) env('API_USAGE_FLUSH_BATCH_SIZE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | Set "connection" to null to use the application's default connection, or
    | name a dedicated connection to keep usage data off your primary database.
    |
    */

    'database' => [
        'connection' => env('API_USAGE_DB_CONNECTION'),

        'tables' => [
            'requests' => env('API_USAGE_TABLE_REQUESTS', 'api_usage_requests'),
            'summaries' => env('API_USAGE_TABLE_SUMMARIES', 'api_usage_summaries'),
        ],

        'consolidation_chunk_size' => (int) env('API_USAGE_CONSOLIDATION_CHUNK_SIZE', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Sampling
    |--------------------------------------------------------------------------
    |
    | A float between 0.0 (record nothing) and 1.0 (record everything). Counts
    | are never scaled back up, so anything below 1.0 reports observed numbers,
    | not estimated totals. Keep it at 1.0 unless you are deliberately trading
    | accuracy for volume.
    |
    */

    'sampling' => [
        'rate' => (float) env('API_USAGE_SAMPLING_RATE', 1.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Recording rules
    |--------------------------------------------------------------------------
    |
    | Request paths that are never recorded. Entries are matched against the
    | path without the leading slash and support the "*" wildcard, exactly like
    | Laravel's own middleware exclusion lists.
    |
    */

    'except' => [
        'up',
        'health',
    ],

    /*
    |--------------------------------------------------------------------------
    | Privacy
    |--------------------------------------------------------------------------
    |
    | Bodies, cookies and authorization headers are never recorded. IP addresses
    | are never stored in clear text: when "hash_ips" is enabled the address is
    | stored as a salted SHA-256 digest, and when disabled no address is
    | recorded at all. Leave "ip_hash_salt" null to derive the salt from the
    | application key — note that rotating APP_KEY then invalidates the ability
    | to correlate old and new hashes.
    |
    | "request_id_headers" are inspected in order; the first non-empty value
    | wins. It is the only header content the package stores.
    |
    */

    'privacy' => [
        'hash_ips' => env('API_USAGE_HASH_IPS', true),
        'ip_hash_salt' => env('API_USAGE_IP_HASH_SALT'),
        'record_user_agent' => env('API_USAGE_RECORD_USER_AGENT', true),

        'request_id_headers' => [
            'X-Request-Id',
            'X-Correlation-Id',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | Raw rows are detailed and grow fastest; summaries are small and are what
    | the query API reads, so they are kept far longer. Set a summary retention
    | to 0 to keep it forever.
    |
    */

    'retention' => [
        'raw_days' => (int) env('API_USAGE_RETENTION_RAW_DAYS', 30),
        'daily_days' => (int) env('API_USAGE_RETENTION_DAILY_DAYS', 730),
        'monthly_months' => (int) env('API_USAGE_RETENTION_MONTHLY_MONTHS', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Middleware auto-registration
    |--------------------------------------------------------------------------
    |
    | When enabled, the package service provider appends the RecordApiUsage
    | middleware to the configured middleware group automatically. Registration
    | is skipped silently if the group does not exist, so an application without
    | API routes is unaffected. Disable this to register the middleware yourself.
    |
    */

    'middleware' => [
        'auto_register' => env('API_USAGE_AUTO_MIDDLEWARE', true),
        'group' => env('API_USAGE_MIDDLEWARE_GROUP', 'api'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduling
    |--------------------------------------------------------------------------
    |
    | When enabled, the package registers the flush/consolidate/prune commands
    | on the application's scheduler. Disable this to schedule them yourself.
    | All times are interpreted in the scheduler's timezone.
    |
    | The query API reads summaries, so "consolidate_today" controls how fresh
    | today's numbers are. Consolidation is idempotent, so re-running it merely
    | recomputes the day. Turn it off on very high-volume installations and
    | accept that today's usage appears after the nightly run instead.
    |
    */

    'schedule' => [
        'enabled' => env('API_USAGE_SCHEDULE_ENABLED', true),
        'flush_minutes' => (int) env('API_USAGE_SCHEDULE_FLUSH_MINUTES', 5),
        'consolidate_today' => env('API_USAGE_SCHEDULE_CONSOLIDATE_TODAY', true),
        'daily_at' => env('API_USAGE_SCHEDULE_DAILY_AT', '02:00'),
        'monthly_at' => env('API_USAGE_SCHEDULE_MONTHLY_AT', '03:00'),
        'prune_at' => env('API_USAGE_SCHEDULE_PRUNE_AT', '03:10'),
    ],

];
