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
     * Set of minute buffers that hold events and have not been claimed yet.
     *
     * The middleware registers a minute when it creates its list, so the flush
     * command finds every buffer however long it was away, instead of having to
     * guess how many minutes back to look.
     */
    public static function pendingRegistry(): string
    {
        return UsageConfig::redisKeyPrefix().'pending';
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

    /**
     * How many times flushing a claimed buffer has failed. Once it passes a
     * threshold, the next attempt isolates the rows the database rejects
     * instead of failing the whole buffer again.
     */
    public static function attemptsFor(string $processingKey): string
    {
        return $processingKey.':attempts';
    }

    /**
     * Events the database refused even on their own, kept for inspection.
     * Capped and expiring, so a systematic problem cannot fill Redis.
     */
    public static function rejected(): string
    {
        return UsageConfig::redisKeyPrefix().'rejected';
    }
}
