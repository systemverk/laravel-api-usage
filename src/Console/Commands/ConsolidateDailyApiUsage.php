<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
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

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];
        $periodStart = $date->toDateString();
        $now = Carbon::now('UTC');

        ApiUsageRequest::query()
            ->select([
                'id', 'actor_type', 'actor_id', 'actor_key', 'credential_id', 'bucket_key',
                'endpoint_key', 'method', 'route_name', 'route_uri', 'status_code', 'duration_ms',
            ])
            ->whereBetween('requested_at', [$date->startOfDay(), $date->endOfDay()])
            ->orderBy('id')
            ->chunkById(
                UsageConfig::consolidationChunkSize(),
                function (Collection $requests) use (&$buckets, $periodStart, $now): void {
                    foreach ($requests as $request) {
                        $key = $request->bucket_key.'|'.$request->endpoint_key;

                        $buckets[$key] ??= SummaryBucket::make(
                            ApiUsageSummary::PERIOD_DAY,
                            $periodStart,
                            [
                                'actor_type' => $request->actor_type,
                                'actor_id' => $request->actor_id,
                                'actor_key' => $request->actor_key,
                                'credential_id' => $request->credential_id,
                                'bucket_key' => $request->bucket_key,
                                'endpoint_key' => $request->endpoint_key,
                                'method' => $request->method,
                                'route_name' => $request->route_name,
                                'route_uri' => $request->route_uri,
                            ],
                            $now
                        );

                        SummaryBucket::addRequest(
                            $buckets[$key],
                            (int) $request->status_code,
                            (int) $request->duration_ms
                        );
                    }
                }
            );

        if ($buckets === []) {
            $this->info('No API usage found for the consolidation window.');

            return self::SUCCESS;
        }

        // Chunked so that a busy day with many actor/endpoint combinations does
        // not build a single oversized upsert statement.
        foreach (array_chunk(array_values($buckets), 500) as $chunk) {
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
