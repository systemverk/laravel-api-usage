<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Support\SummaryBucket;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

class ConsolidateMonthlyApiUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:consolidate-monthly
        {--month= : Month (Y-m) in the application timezone, defaults to previous month}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Consolidate daily API usage summaries into monthly summaries';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! UsageConfig::enabled()) {
            return self::SUCCESS;
        }

        try {
            $monthStart = $this->resolveMonthStart();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $monthEnd = $monthStart->endOfMonth();
        $periodStart = $monthStart->toDateString();
        $now = Carbon::now('UTC');

        /** @var array<string, array<string, mixed>> $buckets */
        $buckets = [];

        ApiUsageSummary::query()
            ->daily()
            // The upper bound carries a time because a date cast writes
            // "Y-m-d 00:00:00" on drivers without a real DATE type, which would
            // otherwise sort after a bare "Y-m-d" for the last day of the month.
            ->whereBetween('period_start', [$periodStart.' 00:00:00', $monthEnd->toDateString().' 23:59:59'])
            ->orderBy('id')
            ->chunkById(
                UsageConfig::consolidationChunkSize(),
                function (Collection $summaries) use (&$buckets, $periodStart, $now): void {
                    foreach ($summaries as $summary) {
                        $key = serialize([
                            $summary->actor_type,
                            $summary->actor_id,
                            (string) $summary->credential_id,
                            $summary->endpoint_key,
                        ]);

                        $buckets[$key] ??= SummaryBucket::make(
                            ApiUsageSummary::PERIOD_MONTH,
                            $periodStart,
                            [
                                'actor_type' => $summary->actor_type,
                                'actor_id' => $summary->actor_id,
                                'credential_id' => $summary->credential_id,
                                'endpoint_key' => $summary->endpoint_key,
                                'method' => $summary->method,
                                'route_name' => $summary->route_name,
                                'route_uri' => $summary->route_uri,
                            ],
                            $now
                        );

                        SummaryBucket::addSummary($buckets[$key], $summary);
                    }
                }
            );

        if ($buckets === []) {
            $this->info('No daily API usage summaries found for the monthly consolidation window.');

            return self::SUCCESS;
        }

        foreach (array_chunk(array_values($buckets), 500) as $chunk) {
            ApiUsageSummary::query()->upsert(
                $chunk,
                SummaryBucket::IDENTITY_COLUMNS,
                SummaryBucket::UPDATE_COLUMNS
            );
        }

        $this->info('Consolidated '.count($buckets).' monthly API usage rows for '.$monthStart->format('Y-m').'.');

        return self::SUCCESS;
    }

    private function resolveMonthStart(): CarbonImmutable
    {
        $monthOption = $this->option('month');

        if ($monthOption === null || (string) $monthOption === '') {
            return CarbonImmutable::now(UsageConfig::timezone())->subMonthNoOverflow()->startOfMonth();
        }

        $monthString = (string) $monthOption;

        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthString)) {
            throw new InvalidArgumentException('Invalid --month format. Expected Y-m, for example 2026-06.');
        }

        try {
            $monthStart = CarbonImmutable::createFromFormat('!Y-m', $monthString, UsageConfig::timezone());
        } catch (\Throwable) {
            throw new InvalidArgumentException('Invalid --month value. Expected a real month in Y-m format.');
        }

        if ($monthStart->format('Y-m') !== $monthString) {
            throw new InvalidArgumentException('Invalid --month value. Expected a real month in Y-m format.');
        }

        return $monthStart->startOfMonth();
    }
}
