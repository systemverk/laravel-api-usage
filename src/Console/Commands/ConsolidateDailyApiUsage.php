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
        {--today : Consolidate the current UTC day instead of yesterday}';

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

        $periodStart = $date->toDateString();
        $now = Carbon::now('UTC');

        // The database does the counting: one pass over the day's rows, and
        // only one result row per actor/endpoint combination comes back. The
        // identity columns are functionally dependent on the grouping key, so
        // MIN() merely picks the (single) value rather than adding a dimension.
        $rows = ApiUsageRequest::query()
            ->toBase()
            ->selectRaw(
                'bucket_key, endpoint_key,
                MIN(actor_type) as actor_type, MIN(actor_id) as actor_id, MIN(actor_key) as actor_key,
                MIN(credential_id) as credential_id, MIN(method) as method,
                MIN(route_name) as route_name, MIN(route_uri) as route_uri,
                COUNT(*) as total_requests,
                SUM(CASE WHEN status_code BETWEEN 100 AND 199 THEN 1 ELSE 0 END) as responses_1xx,
                SUM(CASE WHEN status_code BETWEEN 200 AND 299 THEN 1 ELSE 0 END) as responses_2xx,
                SUM(CASE WHEN status_code BETWEEN 300 AND 399 THEN 1 ELSE 0 END) as responses_3xx,
                SUM(CASE WHEN status_code BETWEEN 400 AND 499 THEN 1 ELSE 0 END) as responses_4xx,
                SUM(CASE WHEN status_code BETWEEN 500 AND 599 THEN 1 ELSE 0 END) as responses_5xx,
                SUM(duration_ms) as total_duration_ms,
                MIN(duration_ms) as min_duration_ms,
                MAX(duration_ms) as max_duration_ms'
            )
            ->whereBetween('requested_at', [$date->startOfDay(), $date->endOfDay()])
            ->groupBy('bucket_key', 'endpoint_key')
            ->get();

        if ($rows->isEmpty()) {
            $this->info('No API usage found for the consolidation window.');

            return self::SUCCESS;
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
                ['period_type', 'period_start', 'bucket_key', 'endpoint_key'],
                SummaryBucket::UPDATE_COLUMNS
            );
        }

        $this->info('Consolidated '.count($buckets).' daily API usage rows for '.$periodStart.'.');

        return self::SUCCESS;
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
