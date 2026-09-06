<?php

namespace Systemverk\LaravelApiUsage\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Models\ApiUsageSummary;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * Report what the package is doing right now.
 *
 * A buffered pipeline is harder to reason about than synchronous inserts, so
 * this command exists to answer "is it working?" without a dashboard. It only
 * ever reads: nothing here mutates usage state.
 */
class ApiUsageStatus extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'api-usage:status';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show API usage tracking status, buffer depth and retention settings';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $redis = $this->redisStatus();
        $database = $this->databaseStatus();

        $this->table(['Setting', 'Value'], [
            ['Enabled', UsageConfig::enabled() ? 'yes' : 'no'],
            ['Sampling rate', (string) UsageConfig::samplingRate()],
            ['Track guests', UsageConfig::trackGuests() ? 'yes' : 'no'],
            ['Actor resolver', UsageConfig::actorResolver()],
            ['Endpoint resolver', UsageConfig::endpointResolver()],
            ['Redis connection', UsageConfig::redisConnection().' — '.$redis['status']],
            ['Pending buffer', $redis['pending']],
            ['Processing buffer', $redis['processing']],
            ['Database connection', (UsageConfig::databaseConnection() ?? 'default').' — '.$database['status']],
            ['Raw rows', $database['requests']],
            ['Daily summaries', $database['daily']],
            ['Monthly summaries', $database['monthly']],
            ['Raw retention', UsageConfig::rawRetentionDays().' days'],
            ['Daily retention', $this->describeRetention(UsageConfig::dailyRetentionDays(), 'days')],
            ['Monthly retention', $this->describeRetention(UsageConfig::monthlyRetentionMonths(), 'months')],
        ]);

        return self::SUCCESS;
    }

    /**
     * @return array{status: string, pending: string, processing: string}
     */
    private function redisStatus(): array
    {
        $unknown = ['status' => 'unreachable', 'pending' => 'unknown', 'processing' => 'unknown'];

        try {
            $connection = Redis::connection(UsageConfig::redisConnection());

            // Only the current and previous minute are counted: scanning every
            // buffer key would mean a KEYS call, which is exactly what a status
            // command must not do to a production Redis.
            $pending = (int) $connection->llen(BufferKeys::currentMinute())
                + (int) $connection->llen(BufferKeys::forMinute(now()->utc()->subMinute()));

            $processing = $connection->smembers(BufferKeys::processingRegistry());

            return [
                'status' => 'connected',
                'pending' => $pending.' events (last two minutes)',
                'processing' => is_array($processing) ? count($processing).' claimed buffers' : 'unknown',
            ];
        } catch (\Throwable $exception) {
            $this->warn('Redis: '.$exception->getMessage());

            return $unknown;
        }
    }

    /**
     * @return array{status: string, requests: string, daily: string, monthly: string}
     */
    private function databaseStatus(): array
    {
        try {
            return [
                'status' => 'connected',
                'requests' => (string) ApiUsageRequest::query()->count(),
                'daily' => (string) ApiUsageSummary::query()->daily()->count(),
                'monthly' => (string) ApiUsageSummary::query()->monthly()->count(),
            ];
        } catch (\Throwable $exception) {
            $this->warn('Database: '.$exception->getMessage());

            return ['status' => 'unreachable', 'requests' => 'unknown', 'daily' => 'unknown', 'monthly' => 'unknown'];
        }
    }

    private function describeRetention(int $value, string $unit): string
    {
        return $value === 0 ? 'kept indefinitely' : $value.' '.$unit;
    }
}
