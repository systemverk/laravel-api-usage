<?php

namespace Systemverk\LaravelApiUsage\Support;

use Systemverk\LaravelApiUsage\Actors\AuthenticatedUserActorResolver;
use Systemverk\LaravelApiUsage\Endpoints\RouteEndpointResolver;

/**
 * Typed accessors for the package configuration.
 *
 * Every read goes through here so that defaults stay in one place and the rest
 * of the package never has to deal with a missing or malformed config file.
 */
class UsageConfig
{
    public static function enabled(): bool
    {
        return (bool) config('api_usage.enabled', true);
    }

    // -----------------------------------------------------------------
    // Actor
    // -----------------------------------------------------------------

    /**
     * @return class-string
     */
    public static function actorResolver(): string
    {
        $resolver = config('api_usage.actor.resolver');

        return is_string($resolver) && $resolver !== ''
            ? $resolver
            : AuthenticatedUserActorResolver::class;
    }

    public static function trackGuests(): bool
    {
        return (bool) config('api_usage.actor.track_guests', true);
    }

    /**
     * Application-supplied callback resolving the credential a request was made
     * with. Registering it in code via UsageRecorder::resolveCredentialUsing()
     * is preferred; a closure placed in the config file works too, but makes the
     * config file uncacheable.
     *
     * @return (callable(\Illuminate\Http\Request): mixed)|null
     */
    public static function credentialResolver(): ?callable
    {
        $resolver = config('api_usage.actor.credential_resolver');

        return is_callable($resolver) ? $resolver : null;
    }

    // -----------------------------------------------------------------
    // Endpoint
    // -----------------------------------------------------------------

    /**
     * @return class-string
     */
    public static function endpointResolver(): string
    {
        $resolver = config('api_usage.endpoint.resolver');

        return is_string($resolver) && $resolver !== ''
            ? $resolver
            : RouteEndpointResolver::class;
    }

    // -----------------------------------------------------------------
    // Buffer
    // -----------------------------------------------------------------

    public static function redisConnection(): string
    {
        $connection = config('api_usage.buffer.connection', 'default');

        return is_string($connection) && $connection !== '' ? $connection : 'default';
    }

    public static function redisKeyPrefix(): string
    {
        $prefix = config('api_usage.buffer.key_prefix', 'api_usage:');

        return is_string($prefix) ? $prefix : 'api_usage:';
    }

    public static function redisTtlSeconds(): int
    {
        return max(60, (int) config('api_usage.buffer.ttl_seconds', 7200));
    }

    public static function flushBatchSize(): int
    {
        return max(1, (int) config('api_usage.buffer.flush_batch_size', 1000));
    }

    // -----------------------------------------------------------------
    // Database
    // -----------------------------------------------------------------

    public static function databaseConnection(): ?string
    {
        $connection = config('api_usage.database.connection');

        return is_string($connection) && $connection !== '' ? $connection : null;
    }

    public static function requestsTable(): string
    {
        $table = config('api_usage.database.tables.requests', 'api_usage_requests');

        return is_string($table) && $table !== '' ? $table : 'api_usage_requests';
    }

    public static function summariesTable(): string
    {
        $table = config('api_usage.database.tables.summaries', 'api_usage_summaries');

        return is_string($table) && $table !== '' ? $table : 'api_usage_summaries';
    }

    public static function consolidationChunkSize(): int
    {
        return max(100, (int) config('api_usage.database.consolidation_chunk_size', 2000));
    }

    // -----------------------------------------------------------------
    // Sampling and exclusions
    // -----------------------------------------------------------------

    /**
     * @return array<int, string>
     */
    public static function exceptPaths(): array
    {
        $paths = config('api_usage.except', []);

        if (! is_array($paths)) {
            return [];
        }

        return array_values(array_filter($paths, is_string(...)));
    }

    public static function samplingRate(): float
    {
        $rate = (float) config('api_usage.sampling.rate', 1.0);

        return max(0.0, min(1.0, $rate));
    }

    // -----------------------------------------------------------------
    // Privacy
    // -----------------------------------------------------------------

    public static function hashIps(): bool
    {
        return (bool) config('api_usage.privacy.hash_ips', true);
    }

    public static function ipHashSalt(): string
    {
        $salt = config('api_usage.privacy.ip_hash_salt');

        if (is_string($salt) && $salt !== '') {
            return $salt;
        }

        return (string) config('app.key');
    }

    public static function recordUserAgent(): bool
    {
        return (bool) config('api_usage.privacy.record_user_agent', true);
    }

    /**
     * @return array<int, string>
     */
    public static function requestIdHeaders(): array
    {
        $headers = config('api_usage.privacy.request_id_headers', ['X-Request-Id', 'X-Correlation-Id']);

        if (! is_array($headers)) {
            return [];
        }

        return array_values(array_filter($headers, is_string(...)));
    }

    // -----------------------------------------------------------------
    // Retention
    // -----------------------------------------------------------------

    public static function rawRetentionDays(): int
    {
        return max(1, (int) config('api_usage.retention.raw_days', 30));
    }

    /**
     * Zero means "keep daily summaries indefinitely".
     */
    public static function dailyRetentionDays(): int
    {
        return max(0, (int) config('api_usage.retention.daily_days', 730));
    }

    /**
     * Zero means "keep monthly summaries indefinitely".
     */
    public static function monthlyRetentionMonths(): int
    {
        return max(0, (int) config('api_usage.retention.monthly_months', 0));
    }
}
