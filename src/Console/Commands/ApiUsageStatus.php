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
        $redis = UsageConfig::usesRedis()
            ? $this->redisStatus()
            : ['status' => 'not used', 'pending' => 'n/a', 'processing' => 'n/a', 'rejected' => 'n/a'];
        $database = $this->databaseStatus();

        $this->table(['Setting', 'Value'], [
            ['Enabled', UsageConfig::enabled() ? 'yes' : 'no'],
            ['Driver', UsageConfig::driver()],
            ['Sampling rate', (string) UsageConfig::samplingRate()],
            ['Track guests', UsageConfig::trackGuests() ? 'yes' : 'no'],
            ['Actor resolver', UsageConfig::actorResolver()],
            ['Endpoint resolver', UsageConfig::endpointResolver()],
            ['Redis connection', UsageConfig::usesRedis() ? UsageConfig::redisConnection().' — '.$redis['status'] : 'not used'],
            ['Pending buffer', $redis['pending']],
            ['Processing buffer', $redis['processing']],
            ['Rejected events', $redis['rejected']],
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
     * @return array{status: string, pending: string, processing: string, rejected: string}
     */
    private function redisStatus(): array
    {
        $unknown = ['status' => 'unreachable', 'pending' => 'unknown', 'processing' => 'unknown', 'rejected' => 'unknown'];

        try {
            $connection = Redis::connection(UsageConfig::redisConnection());

            // The registry names every unclaimed buffer, so no KEYS scan is
            // needed to count what is waiting.
            $buffers = $connection->smembers(BufferKeys::pendingRegistry());
            $buffers = is_array($buffers) ? $buffers : [];

            $pending = 0;

            foreach ($buffers as $buffer) {
                $pending += (int) $connection->llen((string) $buffer);
            }

            $processing = $connection->smembers(BufferKeys::processingRegistry());

            return [
                'status' => 'connected',
                'pending' => $pending.' events in '.count($buffers).' buffers',
                'processing' => is_array($processing) ? count($processing).' claimed buffers' : 'unknown',
                'rejected' => (int) $connection->llen(BufferKeys::rejected()).' events',
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
