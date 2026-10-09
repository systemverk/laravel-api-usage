<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

class PruneApiUsage extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:prune
        {--raw-days= : Override raw retention in days}
        {--chunk=1000 : Rows deleted per statement}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete API usage data older than the configured retention windows';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! UsageConfig::enabled()) {
            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $now = CarbonImmutable::now('UTC');

        $rawDaysOption = $this->option('raw-days');
        $rawDays = $rawDaysOption === null || (string) $rawDaysOption === ''
            ? UsageConfig::rawRetentionDays()
            : max(1, (int) $rawDaysOption);

        $rawDeleted = $this->deleteInChunks(
            fn (): Builder => ApiUsageRequest::query()->where('requested_at', '<', $now->subDays($rawDays)),
            $chunk
        );

        $this->info("Pruned {$rawDeleted} raw API usage rows older than {$rawDays} days.");

        $dailyDays = UsageConfig::dailyRetentionDays();

        if ($dailyDays > 0) {
            $dailyDeleted = $this->deleteInChunks(
                fn (): Builder => ApiUsageSummary::query()
                    ->daily()
                    ->where('period_start', '<', $now->subDays($dailyDays)->toDateString()),
                $chunk
            );

            $this->info("Pruned {$dailyDeleted} daily summaries older than {$dailyDays} days.");
        } else {
            $this->info('Daily summaries are kept indefinitely.');
        }

        $monthlyMonths = UsageConfig::monthlyRetentionMonths();

        if ($monthlyMonths > 0) {
            $monthlyDeleted = $this->deleteInChunks(
                fn (): Builder => ApiUsageSummary::query()
                    ->monthly()
                    ->where('period_start', '<', $now->subMonthsNoOverflow($monthlyMonths)->startOfMonth()->toDateString()),
                $chunk
            );

            $this->info("Pruned {$monthlyDeleted} monthly summaries older than {$monthlyMonths} months.");
        } else {
            $this->info('Monthly summaries are kept indefinitely.');
        }

        return self::SUCCESS;
    }

    /**
     * Delete in chunks so a large backlog does not hold a single long
     * transaction open or exhaust the database's undo/binlog space.
     *
     * The builder is rebuilt per iteration because a builder cannot be reused
     * after delete() has been called on it.
     *
     * @param  \Closure(): Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     */
    private function deleteInChunks(\Closure $query, int $chunk): int
    {
        $deleted = 0;

        do {
            $affected = $query()->limit($chunk)->delete();
            $deleted += $affected;
        } while ($affected > 0);

        return $deleted;
    }
}
