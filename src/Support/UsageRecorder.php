<?php

namespace Systemverk\LaravelApiUsage\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageActor;
use Systemverk\LaravelApiUsage\Contracts\ResolvesUsageEndpoint;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;
use Systemverk\LaravelApiUsage\Events\UsageEvent;

/**
 * Turns an HTTP request/response pair into a {@see UsageEvent}, and a buffered
 * payload back into a database row.
 *
 * Everything here is best effort. A resolver that throws costs us the record,
 * never the request — recording happens in terminate(), after the client
 * already has its response.
 */
class UsageRecorder
{
    /**
     * Application-supplied resolver for the credential a request was made with.
     *
     * @var (callable(Request): mixed)|null
     */
    private static $credentialResolver = null;

    /**
     * Register the callback that resolves the credential behind a request.
     *
     * Typically called from a service provider:
     *
     *     UsageRecorder::resolveCredentialUsing(
     *         fn (Request $request) => $request->user()?->currentAccessToken()?->getKey()
     *     );
     *
     * Pass null to clear it again.
     *
     * @param  (callable(Request): mixed)|null  $resolver
     */
    public static function resolveCredentialUsing(?callable $resolver): void
    {
        self::$credentialResolver = $resolver;
    }

    /**
     * Decide whether a request is eligible for recording.
     *
     * Exclusion is checked before sampling so that excluded paths never consume
     * randomness, which keeps the sampling tests deterministic.
     */
    public static function shouldRecord(Request $request): bool
    {
        if (! UsageConfig::enabled()) {
            return false;
        }

        $except = UsageConfig::exceptPaths();

        if ($except !== [] && $request->is(...$except)) {
            return false;
        }

        $rate = UsageConfig::samplingRate();

        if ($rate >= 1.0) {
            return true;
        }

        if ($rate <= 0.0) {
            return false;
        }

        return (random_int(1, 1_000_000) / 1_000_000) <= $rate;
    }

    /**
     * Build the event for a finished request, or null when it should not be
     * recorded at all — which is what a null actor means.
     */
    public function capture(Request $request, SymfonyResponse $response, float $startedAt): ?UsageEvent
    {
        $actor = $this->resolveActor($request);

        if ($actor === null) {
            return null;
        }

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        return new UsageEvent(
            requestedAt: Carbon::now('UTC'),
            actor: $actor,
            credentialId: $this->resolveCredentialId($request),
            endpoint: $this->resolveEndpoint($request),
            statusCode: $response->getStatusCode(),
            durationMs: max(0, $durationMs),
            ipHash: $this->hashIpAddress($request->ip()),
            userAgent: UsageConfig::recordUserAgent()
                ? self::truncate($request->userAgent(), UsageEvent::MAX_USER_AGENT_LENGTH)
                : null,
            requestId: $this->resolveRequestId($request, $response),
        );
    }

    /**
     * Turn a decoded buffer entry into a row ready for a bulk insert.
     *
     * Returns null for anything the current version cannot read: an unknown
     * payload version, or an entry missing required fields.
     *
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>|null
     */
    public static function prepareForInsert(array $entry): ?array
    {
        if (($entry['v'] ?? null) !== UsageEvent::VERSION) {
            return null;
        }

        $required = ['requested_at', 'actor_key', 'method', 'path', 'endpoint_key', 'status_code', 'duration_ms'];

        foreach ($required as $field) {
            if (! isset($entry[$field]) || ! is_scalar($entry[$field])) {
                return null;
            }
        }

        $now = Carbon::now('UTC');
        $actorKey = mb_substr((string) $entry['actor_key'], 0, UsageEvent::MAX_BUCKET_KEY_LENGTH);
        $credentialId = self::truncate(self::stringOrNull($entry['credential_id'] ?? null), UsageEvent::MAX_CREDENTIAL_ID_LENGTH);

        return [
            'requested_at' => (string) $entry['requested_at'],
            'actor_type' => self::truncate(self::stringOrNull($entry['actor_type'] ?? null), UsageActor::MAX_TYPE_LENGTH),
            'actor_id' => self::truncate(self::stringOrNull($entry['actor_id'] ?? null), UsageActor::MAX_ID_LENGTH),
            'actor_key' => $actorKey,
            'credential_id' => $credentialId,
            'bucket_key' => UsageEvent::bucketKeyFor($actorKey, $credentialId),
            'method' => mb_substr((string) $entry['method'], 0, UsageEndpoint::MAX_METHOD_LENGTH),
            'route_name' => self::truncate(self::stringOrNull($entry['route_name'] ?? null), UsageEndpoint::MAX_ROUTE_NAME_LENGTH),
            'route_uri' => self::truncate(self::stringOrNull($entry['route_uri'] ?? null), UsageEndpoint::MAX_ROUTE_URI_LENGTH),
            'path' => mb_substr((string) $entry['path'], 0, UsageEndpoint::MAX_PATH_LENGTH),
            'endpoint_key' => mb_substr((string) $entry['endpoint_key'], 0, UsageEndpoint::MAX_KEY_LENGTH),
            'status_code' => (int) $entry['status_code'],
            'duration_ms' => max(0, (int) $entry['duration_ms']),
            'ip_hash' => self::truncate(self::stringOrNull($entry['ip_hash'] ?? null), UsageEvent::MAX_IP_HASH_LENGTH),
            'user_agent' => self::truncate(self::stringOrNull($entry['user_agent'] ?? null), UsageEvent::MAX_USER_AGENT_LENGTH),
            'request_id' => self::truncate(self::stringOrNull($entry['request_id'] ?? null), UsageEvent::MAX_REQUEST_ID_LENGTH),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * A resolver that throws costs us the attribution, so the request is
     * dropped rather than silently filed under the wrong actor.
     */
    private function resolveActor(Request $request): ?UsageActor
    {
        try {
            return app(ResolvesUsageActor::class)->resolve($request);
        } catch (\Throwable $exception) {
            self::report('API usage actor resolution failed; the request was not recorded.', $exception);

            return null;
        }
    }

    /**
     * Endpoint resolution has a safe fallback — the raw path — so a broken
     * resolver costs detail rather than the whole record.
     */
    private function resolveEndpoint(Request $request): UsageEndpoint
    {
        try {
            return app(ResolvesUsageEndpoint::class)->resolve($request);
        } catch (\Throwable $exception) {
            self::report('API usage endpoint resolution failed; falling back to the request path.', $exception);

            return UsageEndpoint::make($request->method(), null, null, $request->path());
        }
    }

    /**
     * Ask the application which credential — API key, personal access token,
     * OAuth client — the request authenticated with.
     *
     * A resolver registered in code wins over one placed in the config file.
     */
    private function resolveCredentialId(Request $request): ?string
    {
        $resolver = self::$credentialResolver ?? UsageConfig::credentialResolver();

        if ($resolver === null) {
            return null;
        }

        try {
            $credentialId = $resolver($request);
        } catch (\Throwable $exception) {
            self::report('API usage credential resolution failed; the request was recorded without one.', $exception);

            return null;
        }

        if (! is_scalar($credentialId)) {
            return null;
        }

        return self::truncate(trim((string) $credentialId), UsageEvent::MAX_CREDENTIAL_ID_LENGTH);
    }

    private function hashIpAddress(?string $ipAddress): ?string
    {
        if (! UsageConfig::hashIps() || $ipAddress === null || $ipAddress === '') {
            return null;
        }

        return hash('sha256', UsageConfig::ipHashSalt().$ipAddress);
    }

    private function resolveRequestId(Request $request, SymfonyResponse $response): ?string
    {
        foreach (UsageConfig::requestIdHeaders() as $header) {
            $candidate = $request->headers->get($header) ?? $response->headers->get($header);

            if (! is_string($candidate)) {
                continue;
            }

            $candidate = trim($candidate);

            if ($candidate !== '') {
                return mb_substr($candidate, 0, UsageEvent::MAX_REQUEST_ID_LENGTH);
            }
        }

        return null;
    }

    private static function report(string $message, \Throwable $exception): void
    {
        try {
            Log::warning($message, [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable) {
            //
        }
    }

    private static function stringOrNull(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    private static function truncate(?string $value, int $length): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return mb_substr($value, 0, $length);
    }
}
