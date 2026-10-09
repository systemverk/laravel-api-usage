<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Support\SummaryBucket;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

class ConsolidateDailyApiUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:consolidate-daily
        {--date= : UTC date (Y-m-d), defaults to yesterday}
        {--today : Consolidate the current UTC day instead of yesterday}
        {--days=1 : Consolidate this many days, ending at the chosen day}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate one day of raw API usage into daily summaries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! UsageConfig::enabled()) {
            return self::SUCCESS;
        }

        try {
            $date = $this->resolveDate();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        try {
            $days = $this->resolveDays();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        // The chosen day always runs. Earlier ones pick up events flushed late
        // — after a backlog or a recovery — but only while their raw rows are
        // all still there: recomputing a half-pruned day would understate it.
        $oldestIntact = CarbonImmutable::now('UTC')->subDays(UsageConfig::rawRetentionDays());

        for ($offset = 0; $offset < $days; $offset++) {
            $day = $date->subDays($offset);

            if ($offset > 0 && $day->startOfDay()->lessThan($oldestIntact)) {
                $this->info("Skipped {$day->toDateString()}: its raw rows may already be pruned.");

                continue;
            }

            $this->consolidate($day);
        }

        return self::SUCCESS;
    }

    private function consolidate(CarbonImmutable $date): void
    {
        $periodStart = $date->toDateString();
        $now = Carbon::now('UTC');

        // The database does the counting: one pass over the day's rows, and
        // only one result row per actor/credential/endpoint combination comes
        // back. The method and route columns follow from the endpoint key, so
        // MIN() merely picks their (single) value rather than adding a dimension.
        $rows = ApiUsageRequest::query()
            ->toBase()
            ->selectRaw(
                'actor_type, actor_id, credential_id, endpoint_key,
                MIN(method) as method,
                MIN(route_name) as route_name, MIN(route_uri) as route_uri,
                COUNT(*) as total_requests,
                SUM(CASE WHEN status_code BETWEEN 100 AND 199 THEN 1 ELSE 0 END) as responses_1xx,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as responses_2xx,
                SUM(CASE WHEN status_code BETWEEN 300 AND 399 THEN 1 ELSE 0 END) as responses_3xx,
                SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as responses_4xx,
                SUM(CASE WHEN status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) as responses_5xx,
                SUM(CASE WHEN status_code = 429 THEN 1 ELSE 0 END) as responses_429,
                SUM(duration_ms) as total_duration_ms,
                MIN(duration_ms) as min_duration_ms,
                MAX(duration_ms) as max_duration_ms'
            )
            ->whereBetween('requested_at', [$date->startOfDay(), $date->endOfDay()])
            ->groupBy('actor_type', 'actor_id', 'credential_id', 'endpoint_key')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No API usage found for '.$periodStart.'.');

            return;
        }

        $buckets = $rows
            ->map(fn (object $row): array => SummaryBucket::fromAggregate(
                ApiUsageSummary::PERIOD_DAY,
                $periodStart,
                $row,
                $now
            ))
            ->all();

        // Chunked so that a busy day with many actor/endpoint combinations does
        // not build a single oversized upsert statement.
        foreach (array_chunk($buckets, 500) as $chunk) {
            ApiUsageSummary::query()->upsert(
                $chunk,
                SummaryBucket::IDENTITY_COLUMNS,
                SummaryBucket::UPDATE_COLUMNS
            );
        }

        $this->info('Consolidated '.count($buckets).' daily API usage rows for '.$periodStart.'.');
    }

    private function resolveDays(): int
    {
        $days = (string) $this->option('days');

        if (! ctype_digit($days) || (int) $days < 1) {
            throw new InvalidArgumentException('Invalid --days value. Expected a whole number of at least 1.');
        }

        return (int) $days;
    }

    private function resolveDate(): CarbonImmutable
    {
        $dateOption = $this->option('date');
        $hasDate = $dateOption !== null && (string) $dateOption !== '';

        if ($this->option('today')) {
            if ($hasDate) {
                throw new InvalidArgumentException('Use either --today or --date, not both.');
            }

            return CarbonImmutable::now('UTC');
        }

        if (! $hasDate) {
            return CarbonImmutable::now('UTC')->subDay();
        }

        $dateString = (string) $dateOption;

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            throw new InvalidArgumentException('Invalid --date format. Expected Y-m-d, for example 2026-06-17.');
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $dateString, 'UTC');
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid --date value. Expected a real UTC date in Y-m-d format.');
        }

        if ($date->format('Y-m-d') !== $dateString) {
            throw new InvalidArgumentException('Invalid --date value. Expected a real UTC date in Y-m-d format.');
        }

        return $date;
    }
}
