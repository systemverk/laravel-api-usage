<?php

namespace Systemverk\LaravelApiUsage\Storage;

use Illuminate\Support\Facades\Redis;
use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * Appends events to a per-minute Redis list, for `api-usage:flush` to move into
 * SQL in batches.
 */
class RedisBuffer implements StoresUsageEvents
{
    /**
     * A missing or misnamed Redis connection is treated as "disabled" rather
     * than an error: usage tracking must never take an application down.
     */
    public function isAvailable(): bool
    {
        $connection = UsageConfig::redisConnection();

        return config("database.redis.{$connection}") !== null
            || config("database.redis.clusters.{$connection}") !== null;
    }

    public function store(UsageEvent $event): void
    {
        $serialized = json_encode($event->toPayload(), JSON_THROW_ON_ERROR);
        $key = BufferKeys::currentMinute();

        $redis = Redis::connection(UsageConfig::redisConnection());

        // Only the request that creates the minute's list has to register it
        // and give it a TTL. Every later request costs a single round trip, and
        // a list can never be left behind without an expiry by a failure
        // between two unconditional commands. The flush command removes the
        // registration before it claims a buffer, so the first request after a
        // claim registers the fresh list again.
        if ((int) $redis->rpush($key, $serialized) === 1) {
            $redis->sadd(BufferKeys::pendingRegistry(), $key);
            $redis->expire($key, UsageConfig::redisTtlSeconds());
        }
    }
}
