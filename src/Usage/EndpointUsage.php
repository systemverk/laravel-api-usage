<?php

namespace Systemverk\LaravelApiUsage\Usage;

/**
 * Aggregated usage for one logical endpoint.
 */
final readonly class EndpointUsage
{
    public function __construct(
        public string $endpointKey,
        public string $method,
        public ?string $routeName,
        public ?string $routeUri,
        public UsageSummary $summary,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            endpointKey: (string) ($row['endpoint_key'] ?? ''),
            method: (string) ($row['method'] ?? ''),
            routeName: isset($row['route_name']) ? (string) $row['route_name'] : null,
            routeUri: isset($row['route_uri']) ? (string) $row['route_uri'] : null,
            summary: UsageSummary::fromTotals($row),
        );
    }

    public function requestsTotal(): int
    {
        return $this->summary->totalRequests;
    }

    public function averageDurationMs(): ?float
    {
        return $this->summary->averageDurationMs;
    }

    public function clientErrors(): int
    {
        return $this->summary->clientErrors;
    }

    public function serverErrors(): int
    {
        return $this->summary->serverErrors;
    }

    /**
     * A human-readable name for the endpoint, preferring the route name.
     */
    public function label(): string
    {
        return $this->routeName ?? $this->routeUri ?? $this->endpointKey;
    }
}
