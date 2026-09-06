<?php

namespace Systemverk\LaravelApiUsage\Usage;

use Illuminate\Support\Collection;

/**
 * "Who uses the API most, and who sees the most failures?"
 *
 *     ApiUsage::actors()->lastDays(7)->mostActive();
 *
 * Different actor types never collide: an organization with id 42 and a user
 * with id 42 are two rows, because grouping is on the type as well as the id.
 */
class ActorQuery extends PeriodQuery
{
    public const DEFAULT_LIMIT = 10;

    /**
     * @return Collection<int, ActorUsage>
     */
    public function mostActive(int $limit = self::DEFAULT_LIMIT): Collection
    {
        return new Collection($this->ordered('total_requests', $limit));
    }

    /**
     * @return Collection<int, ActorUsage>
     */
    public function mostErrors(int $limit = self::DEFAULT_LIMIT): Collection
    {
        return new Collection($this->ordered('error_responses', $limit));
    }

    /**
     * Every actor in the period, ordered by request count.
     *
     * @return Collection<int, ActorUsage>
     */
    public function all(): Collection
    {
        return new Collection($this->ordered('total_requests', null));
    }

    /**
     * @return array<int, ActorUsage>
     */
    private function ordered(string $column, ?int $limit): array
    {
        $groupBy = ['actor_type', 'actor_id', 'actor_key'];

        $select = array_merge($groupBy, $this->totalsSelect(), [
            '(sum(responses_4xx) + sum(responses_5xx)) as error_responses',
            'case when sum(total_requests) > 0 then sum(total_duration_ms) * 1.0 / sum(total_requests) else 0 end as avg_duration_ms',
        ]);

        $query = $this->baseQuery()
            ->selectRaw(implode(', ', $select))
            ->groupBy($groupBy)
            // actor_key breaks ties so that ordering is stable.
            ->orderByDesc($column)
            ->orderBy('actor_key');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        $actors = [];

        foreach ($query->get() as $row) {
            $actors[] = ActorUsage::fromRow($row->getAttributes());
        }

        return $actors;
    }
}
