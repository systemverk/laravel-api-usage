<?php

namespace Systemverk\LaravelApiUsage\Support;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Names of the Redis keys the buffer is built from.
 */
final class BufferKeys
{
    /**
     * The per-minute list the middleware appends to.
     */
    public static function currentMinute(): string
    {
        return self::forMinute(Carbon::now('UTC'));
    }

    public static function forMinute(CarbonInterface $minute): string
    {
        return UsageConfig::redisKeyPrefix().'requests:'.$minute->format('YmdHi');
    }

    /**
     * Set tracking buffers that have been claimed for flushing but not yet
     * confirmed written to the database, so a crashed flush can be retried.
     */
    public static function processingRegistry(): string
    {
        return UsageConfig::redisKeyPrefix().'processing';
    }

    public static function lockFor(string $processingKey): string
    {
        return $processingKey.':lock';
    }
}
