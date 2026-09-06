<?php

namespace Systemverk\LaravelApiUsage\Usage;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;

/**
 * Period selection and filtering shared by the usage, endpoint and actor
 * queries.
 *
 * All three read daily summaries rather than raw rows: summaries are small,
 * outlive raw retention by default, and already carry the duration totals the
 * result objects need. The trade-off is freshness — numbers are as current as
 * the last consolidation run. See the scheduling section of the README.
 *
 * Instances are immutable; every method returns a new query.
 */
abstract class PeriodQuery
{
    protected string $from;

    protected string $to;

    protected ?UsageActor $actor = null;

    protected ?string $actorType = null;

    protected ?string $credentialId = null;

    protected ?string $endpointKey = null;

    public function __construct()
    {
        // An unqualified query means "this month", the period people ask for
        // most often, rather than an unbounded table scan.
        $now = Carbon::now('UTC');

        $this->from = $now->copy()->startOfMonth()->toDateString();
        $this->to = $now->copy()->endOfMonth()->toDateString();
    }

    public function today(): static
    {
        $today = Carbon::now('UTC')->toDateString();

        return $this->betweenDates($today, $today);
    }

    public function yesterday(): static
    {
        $yesterday = Carbon::now('UTC')->subDay()->toDateString();

        return $this->betweenDates($yesterday, $yesterday);
    }

    /**
     * The current week, honouring the application's configured first day.
     */
    public function thisWeek(): static
    {
        $now = Carbon::now('UTC');

        return $this->betweenDates(
            $now->copy()->startOfWeek()->toDateString(),
            $now->copy()->endOfWeek()->toDateString()
        );
    }

    /**
     * The last N days including today, so lastDays(1) means today alone.
     */
    public function lastDays(int $days): static
    {
        $days = max(1, $days);
        $now = Carbon::now('UTC');

        return $this->betweenDates(
            $now->copy()->subDays($days - 1)->toDateString(),
            $now->toDateString()
        );
    }

    public function thisMonth(): static
    {
        $now = Carbon::now('UTC');

        return $this->betweenDates(
            $now->copy()->startOfMonth()->toDateString(),
            $now->copy()->endOfMonth()->toDateString()
        );
    }

    public function lastMonth(): static
    {
        $lastMonth = Carbon::now('UTC')->subMonthNoOverflow();

        return $this->betweenDates(
            $lastMonth->copy()->startOfMonth()->toDateString(),
            $lastMonth->copy()->endOfMonth()->toDateString()
        );
    }

    /**
     * An arbitrary range. Both ends are inclusive and interpreted as UTC dates;
     * reversed arguments are swapped rather than silently returning nothing.
     */
    public function between(DateTimeInterface $from, DateTimeInterface $to): static
    {
        return $this->betweenDates(
            Carbon::instance($from)->utc()->toDateString(),
            Carbon::instance($to)->utc()->toDateString()
        );
    }

    public function forActor(UsageActor $actor): static
    {
        $clone = clone $this;
        $clone->actor = $actor;

        return $clone;
    }

    /**
     * Every actor of one type — all organizations, say.
     */
    public function forActorType(string $type): static
    {
        $clone = clone $this;
        $clone->actorType = mb_strtolower(trim($type));

        return $clone;
    }

    public function forCredential(string|int $credentialId): static
    {
        $clone = clone $this;
        $clone->credentialId = trim((string) $credentialId);

        return $clone;
    }

    /**
     * @param  string  $endpointKey  e.g. "GET:api.orders.show"
     */
    public function forEndpoint(string $endpointKey): static
    {
        $clone = clone $this;
        $clone->endpointKey = trim($endpointKey);

        return $clone;
    }

    protected function betweenDates(string $from, string $to): static
    {
        $clone = clone $this;

        [$clone->from, $clone->to] = $from <= $to ? [$from, $to] : [$to, $from];

        return $clone;
    }

    /**
     * Roll the selected period and filters up into a single summary.
     */
    public function summary(): UsageSummary
    {
        $totals = $this->baseQuery()
            ->selectRaw(implode(', ', $this->totalsSelect()))
            ->first();

        if ($totals === null) {
            return UsageSummary::empty();
        }

        return UsageSummary::fromTotals($totals->getAttributes());
    }

    /**
     * Total recorded requests — the shorthand behind quota checks.
     */
    public function count(): int
    {
        return (int) $this->baseQuery()->sum('total_requests');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<ApiUsageSummary>
     */
    protected function baseQuery(): Builder
    {
        $query = ApiUsageSummary::query()
            ->daily()
            // The upper bound carries a time because a date cast writes
            // "Y-m-d 00:00:00" on drivers without a real DATE type, and a bare
            // "Y-m-d" would then sort before the very day it selects.
            ->whereBetween('period_start', [$this->from.' 00:00:00', $this->to.' 23:59:59']);

        if ($this->actor !== null) {
            $query->forActor($this->actor);
        }

        if ($this->actorType !== null) {
            $query->where('actor_type', $this->actorType);
        }

        if ($this->credentialId !== null) {
            $query->where('credential_id', $this->credentialId);
        }

        if ($this->endpointKey !== null) {
            $query->where('endpoint_key', $this->endpointKey);
        }

        return $query;
    }

    /**
     * The aggregate expressions every result object is built from.
     *
     * @return array<int, string>
     */
    protected function totalsSelect(): array
    {
        return [
            'sum(total_requests) as total_requests',
            'sum(responses_1xx) as responses_1xx',
            'sum(responses_2xx) as responses_2xx',
            'sum(responses_3xx) as responses_3xx',
            'sum(responses_4xx) as responses_4xx',
            'sum(responses_5xx) as responses_5xx',
            'sum(total_duration_ms) as total_duration_ms',
            'min(min_duration_ms) as min_duration_ms',
            'max(max_duration_ms) as max_duration_ms',
        ];
    }
}
