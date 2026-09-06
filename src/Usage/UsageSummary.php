<?php

namespace Systemverk\LaravelApiUsage\Usage;

/**
 * The answer to "how much was this used, and how did it perform?".
 *
 * Counts reflect what was recorded. With a sampling rate below 1.0 that is a
 * sample, not an estimate of the true total — the package never extrapolates.
 */
final readonly class UsageSummary
{
    public function __construct(
        public int $totalRequests = 0,
        public int $informational = 0,
        public int $successfulRequests = 0,
        public int $redirects = 0,
        public int $clientErrors = 0,
        public int $serverErrors = 0,
        public int $totalDurationMs = 0,
        public ?float $averageDurationMs = null,
        public ?int $minDurationMs = null,
        public ?int $maxDurationMs = null,
    ) {}

    /**
     * Build a summary from summed summary columns.
     *
     * @param  array<string, mixed>  $totals
     */
    public static function fromTotals(array $totals): self
    {
        $requests = (int) ($totals['total_requests'] ?? 0);

        if ($requests === 0) {
            return self::empty();
        }

        $duration = (int) ($totals['total_duration_ms'] ?? 0);

        return new self(
            totalRequests: $requests,
            informational: (int) ($totals['responses_1xx'] ?? 0),
            successfulRequests: (int) ($totals['responses_2xx'] ?? 0),
            redirects: (int) ($totals['responses_3xx'] ?? 0),
            clientErrors: (int) ($totals['responses_4xx'] ?? 0),
            serverErrors: (int) ($totals['responses_5xx'] ?? 0),
            totalDurationMs: $duration,
            averageDurationMs: round($duration / $requests, 2),
            minDurationMs: isset($totals['min_duration_ms']) ? (int) $totals['min_duration_ms'] : null,
            maxDurationMs: isset($totals['max_duration_ms']) ? (int) $totals['max_duration_ms'] : null,
        );
    }

    /**
     * A period with no recorded usage. Every count is zero and every duration
     * is null — never a misleading zero millisecond average.
     */
    public static function empty(): self
    {
        return new self;
    }

    public function hasUsage(): bool
    {
        return $this->totalRequests > 0;
    }

    /**
     * Share of requests that failed, as a fraction between 0.0 and 1.0.
     *
     * Returns 0.0 for an empty period rather than dividing by zero.
     */
    public function errorRate(): float
    {
        if ($this->totalRequests === 0) {
            return 0.0;
        }

        return round(($this->clientErrors + $this->serverErrors) / $this->totalRequests, 4);
    }

    public function serverErrorRate(): float
    {
        if ($this->totalRequests === 0) {
            return 0.0;
        }

        return round($this->serverErrors / $this->totalRequests, 4);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_requests' => $this->totalRequests,
            'informational' => $this->informational,
            'successful_requests' => $this->successfulRequests,
            'redirects' => $this->redirects,
            'client_errors' => $this->clientErrors,
            'server_errors' => $this->serverErrors,
            'total_duration_ms' => $this->totalDurationMs,
            'average_duration_ms' => $this->averageDurationMs,
            'min_duration_ms' => $this->minDurationMs,
            'max_duration_ms' => $this->maxDurationMs,
            'error_rate' => $this->errorRate(),
        ];
    }
}
