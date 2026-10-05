<?php

namespace Systemverk\LaravelApiUsage\Storage;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;
use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * Appends events to a per-minute Redis list, for `api-usage:flush` to move into
 * SQL in batches.
 *
 * Redis Cluster is not supported: the flush command renames a buffer to a key
 * in another hash slot, which a cluster refuses.
 */
class RedisBuffer implements StoresUsageEvents
{
    /**
     * KEYS: the minute's list, the pending registry.
     * ARGV: the serialized event, the list's name, the TTL in seconds.
     */
    private const PUSH_SCRIPT = <<<'LUA'
if redis.call('RPUSH', KEYS[1], ARGV[1]) == 1 then
    redis.call('SADD', KEYS[2], ARGV[2])
    redis.call('EXPIRE', KEYS[1], ARGV[3])
end
return 1
LUA;

    /**
     * A missing or misnamed Redis connection is treated as "disabled" rather
     * than an error: usage tracking must never take an application down.
     */
    public function isAvailable(): bool
    {
        $connection = UsageConfig::redisConnection();

        return config("database.redis.{$connection}") !== null;
    }

    public function store(UsageEvent $event): void
    {
        $serialized = json_encode($event->toPayload(), JSON_THROW_ON_ERROR);
        $key = BufferKeys::currentMinute();

        // One script, so a crash can never leave a list that is registered
        // nowhere or has no expiry. Only the request that creates the minute's
        // list registers it and sets its TTL; every later request costs a
        // single round trip. The flush command removes the registration before
        // it claims a buffer, so the first request after a claim registers the
        // fresh list again.
        //
        // The registry member travels as an argument rather than a key: a
        // client-side key prefix is applied to KEYS only, and the registry must
        // hold the same unprefixed name the flush command reads back.
        $redis = Redis::connection(UsageConfig::redisConnection());

        // Laravel's connection takes the script, the key count, then keys and
        // arguments. Spread from an array because static analysis otherwise
        // reads the call as phpredis's own eval(), which orders them differently.
        /** @var array<int, mixed> $arguments */
        $arguments = [
            self::PUSH_SCRIPT,
            2,
            $key,
            BufferKeys::pendingRegistry(),
            $serialized,
            $key,
            UsageConfig::redisTtlSeconds(),
        ];

        $redis->eval(...$arguments);
    }
}
