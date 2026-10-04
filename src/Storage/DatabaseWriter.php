<?php

namespace Systemverk\LaravelApiUsage\Storage;

use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Models\ApiUsageRequest;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

/**
 * Writes each event straight into `api_usage_requests`, with no Redis and no
 * flush step.
 *
 * This costs one INSERT per request, run after the response has been sent. That
 * is fine for a small application and a poor trade at volume, where the Redis
 * buffer's batched inserts are far cheaper. Pair it with a dedicated database
 * connection (`api_usage.database.connection`) so usage writes never compete
 * with the application's own.
 */
class DatabaseWriter implements StoresUsageEvents
{
    public function isAvailable(): bool
    {
        return true;
    }

    public function store(UsageEvent $event): void
    {
        // The same validation and truncation a flushed Redis entry goes
        // through, so both drivers produce identical rows.
        $row = UsageRecorder::prepareForInsert($event->toPayload());

        if ($row === null) {
            return;
        }

        ApiUsageRequest::query()->insert($row);
    }
}
