<?php

namespace Systemverk\LaravelApiUsage\Usage;

use Illuminate\Support\Collection;

/**
 * "Which endpoints are used most, are slowest, or fail most often?"
 *
 *     ApiUsage::endpoints()->thisMonth()->slowest(5);
 */
class EndpointQuery extends PeriodQuery
{
    public const DEFAULT_LIMIT = 10;

    /**
     * @return Collection<int, EndpointUsage>
     */
    public function mostUsed(int $limit = self::DEFAULT_LIMIT): Collection
    {
        return new Collection($this->ordered('total_requests', $limit));
    }

    /**
     * Ordered by mean duration. An endpoint called once is as eligible as one
     * called a million times, so pair this with mostUsed() when it matters.
     *
     * @return Collection<int, EndpointUsage>
     */
    public function slowest(int $limit = self::DEFAULT_LIMIT): Collection
    {
        return new Collection($this->ordered('avg_duration_ms', $limit));
    }

    /**
     * @return Collection<int, EndpointUsage>
     */
    public function mostErrors(int $limit = self::DEFAULT_LIMIT): Collection
    {
        return new Collection($this->ordered('error_responses', $limit));
    }

    /**
     * Every endpoint in the period, ordered by request count.
     *
     * @return Collection<int, EndpointUsage>
     */
    public function all(): Collection
    {
        return new Collection($this->ordered('total_requests', null));
    }

    /**
     * @return array<int, EndpointUsage>
     */
    private function ordered(string $column, ?int $limit): array
    {
        $groupBy = ['endpoint_key', 'method', 'route_name', 'route_uri'];

        $select = array_merge($groupBy, $this->totalsSelect(), [
            '(sum(responses_4xx) + sum(responses_5xx)) as error_responses',
            // Guarded against a zero denominator even though a stored row
            // always has at least one request.
            'case when sum(total_requests) > 0 then sum(total_duration_ms) * 1.0 / sum(total_requests) else 0 end as avg_duration_ms',
        ]);

        $query = $this->baseQuery()
            ->selectRaw(implode(', ', $select))
            ->groupBy($groupBy)
            // endpoint_key breaks ties so that ordering is stable.
            ->orderByDesc($column)
            ->orderBy('endpoint_key');

        if ($limit !== null) {
            $query->limit(max(1, $limit));
        }

        $endpoints = [];

        foreach ($query->get() as $row) {
            $endpoints[] = EndpointUsage::fromRow($row->getAttributes());
        }

        return $endpoints;
    }
}
