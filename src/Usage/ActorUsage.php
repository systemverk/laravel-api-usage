<?php

namespace Systemverk\LaravelApiUsage\Usage;

use Systemverk\LaravelApiUsage\Actors\UsageActor;

/**
 * Aggregated usage for one actor.
 */
final readonly class ActorUsage
{
    /**
     * Stable, groupable identity: "type:id", or plain "guest". Derived from the
     * actor columns rather than stored in the database.
     */
    public string $actorKey;

    public function __construct(
        public ?string $actorType,
        public ?string $actorId,
        public UsageSummary $summary,
    ) {
        $this->actorKey = UsageActor::fromStored($actorType, $actorId)?->key() ?? '';
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            actorType: isset($row['actor_type']) ? (string) $row['actor_type'] : null,
            actorId: isset($row['actor_id']) ? (string) $row['actor_id'] : null,
            summary: UsageSummary::fromTotals($row),
        );
    }

    /**
     * Rebuild the actor value object, so a result can be fed straight back into
     * another query. Null for rows recorded without actor columns.
     */
    public function actor(): ?UsageActor
    {
        return UsageActor::fromStored($this->actorType, $this->actorId);
    }

    public function requestsTotal(): int
    {
        return $this->summary->totalRequests;
    }

    public function clientErrors(): int
    {
        return $this->summary->clientErrors;
    }

    public function serverErrors(): int
    {
        return $this->summary->serverErrors;
    }

    public function averageDurationMs(): ?float
    {
        return $this->summary->averageDurationMs;
    }
}
