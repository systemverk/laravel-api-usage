<?php

namespace Systemverk\LaravelApiUsage\Events;

use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;

/**
 * One recorded API call, as it travels from the middleware through Redis into
 * SQL. Actor and endpoint identity are resolved before buffering so that raw
 * rows and aggregates always agree on who called what.
 */
final readonly class UsageEvent
{
    /**
     * Serialized payload version.
     *
     * Bump this whenever the payload shape changes in a way an older flush
     * command could misread. Entries carrying an unknown version are discarded
     * rather than half-decoded.
     */
    public const VERSION = 3;

    public const MAX_CREDENTIAL_ID_LENGTH = 64;

    public const MAX_USER_AGENT_LENGTH = 512;

    public const MAX_REQUEST_ID_LENGTH = 64;

    public const MAX_IP_HASH_LENGTH = 64;

    public function __construct(
        public Carbon $requestedAt,
        public UsageActor $actor,
        public ?string $credentialId,
        public UsageEndpoint $endpoint,
        public int $statusCode,
        public int $durationMs,
        public ?string $ipHash = null,
        public ?string $userAgent = null,
        public ?string $requestId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'v' => self::VERSION,
            'requested_at' => $this->requestedAt->toDateTimeString(),
            'actor_type' => $this->actor->type,
            'actor_id' => $this->actor->id,
            'credential_id' => $this->credentialId,
            'method' => $this->endpoint->method,
            'route_name' => $this->endpoint->routeName,
            'route_uri' => $this->endpoint->routeUri,
            'path' => $this->endpoint->path,
            'endpoint_key' => $this->endpoint->key(),
            'status_code' => $this->statusCode,
            'duration_ms' => $this->durationMs,
            'ip_hash' => $this->ipHash,
            'user_agent' => $this->userAgent,
            'request_id' => $this->requestId,
        ];
    }
}
