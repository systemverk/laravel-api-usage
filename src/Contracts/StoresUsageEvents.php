<?php

namespace Systemverk\LaravelApiUsage\Contracts;

use Systemverk\LaravelApiUsage\Events\UsageEvent;

/**
 * Where a finished request goes first.
 *
 * Implementations are the package's two drivers: a Redis buffer that a
 * scheduled command later flushes into SQL, and a direct database write.
 */
interface StoresUsageEvents
{
    /**
     * Whether the driver has what it needs to store anything. Checked before an
     * event is even built, so an unconfigured driver costs nothing per request.
     */
    public function isAvailable(): bool;

    /**
     * Store one event. May throw; the caller reports and swallows the failure.
     */
    public function store(UsageEvent $event): void;
}
