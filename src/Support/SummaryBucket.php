<?php

namespace Systemverk\LaravelApiUsage\Support;

use Illuminate\Support\Carbon;

/**
 * Shared shape of an aggregated usage row.
 *
 * Both consolidation commands build the same rows — one from raw requests
 * aggregated by the database, one from daily summaries — so the column list
 * lives in a single place.
 */
final class SummaryBucket
{
    /**
     * Counter columns that are simply summed when rolling a period up.
     */
    public const COUNTERS = [
        'total_requests',
        'responses_1xx',
        'responses_2xx',
        'responses_3xx',
        'responses_4xx',
        'responses_5xx',
        'total_duration_ms',
    ];

    /**
     * The columns that identify a summary row. They match the unique index
     * consolidation upserts on, so a rerun replaces a row rather than adding one.
     */
    public const IDENTITY_COLUMNS = [
        'period_type',
        'period_start',
        'actor_type',
        'actor_id',
        'credential_id',
        'endpoint_key',
    ];

    /**
     * Columns refreshed when an existing row is upserted.
     *
     * Consolidation always recomputes a period from scratch, so refreshing
     * rather than incrementing is what makes reruns idempotent.
     */
    public const UPDATE_COLUMNS = [
        'total_requests',
        'responses_1xx',
        'responses_2xx',
        'responses_3xx',
        'responses_4xx',
        'responses_5xx',
        'total_duration_ms',
        'min_duration_ms',
        'max_duration_ms',
        'method',
        'route_name',
        'route_uri',
        'updated_at',
    ];

    /**
     * @param  array<string, mixed>  $identity  actor/endpoint columns copied onto the row
     * @return array<string, mixed>
     */
    public static function make(string $periodType, string $periodStart, array $identity, Carbon $now): array
    {
        return [
            'period_type' => $periodType,
            'period_start' => $periodStart,
            'actor_type' => (string) ($identity['actor_type'] ?? ''),
            'actor_id' => (string) ($identity['actor_id'] ?? ''),
            'credential_id' => (string) ($identity['credential_id'] ?? ''),
            'endpoint_key' => (string) ($identity['endpoint_key'] ?? ''),
            'method' => (string) ($identity['method'] ?? ''),
            'route_name' => $identity['route_name'] ?? null,
            'route_uri' => $identity['route_uri'] ?? null,
            'total_requests' => 0,
            'responses_1xx' => 0,
            'responses_2xx' => 0,
            'responses_3xx' => 0,
            'responses_4xx' => 0,
            'responses_5xx' => 0,
            'total_duration_ms' => 0,
            'min_duration_ms' => 0,
            'max_duration_ms' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    /**
     * Build a bucket from a row the database has already aggregated.
     *
     * The row carries the identity columns plus every counter, and the totals
     * are authoritative: codes outside 100-599 count towards `total_requests`
     * without belonging to a class column. Drivers differ on whether SUM()
     * comes back as an int or a string, hence the casts.
     *
     * @return array<string, mixed>
     */
    public static function fromAggregate(string $periodType, string $periodStart, object $row, Carbon $now): array
    {
        $bucket = self::make($periodType, $periodStart, (array) $row, $now);

        foreach (self::COUNTERS as $column) {
            $bucket[$column] = (int) ($row->{$column} ?? 0);
        }

        $bucket['min_duration_ms'] = (int) ($row->min_duration_ms ?? 0);
        $bucket['max_duration_ms'] = (int) ($row->max_duration_ms ?? 0);

        return $bucket;
    }

    /**
     * Fold one already-aggregated row into a bucket.
     *
     * @param  array<string, mixed>  $bucket
     */
    public static function addSummary(array &$bucket, object $summary): void
    {
        $first = $bucket['total_requests'] === 0;
        $rowRequests = (int) ($summary->total_requests ?? 0);

        foreach (self::COUNTERS as $column) {
            $bucket[$column] += (int) ($summary->{$column} ?? 0);
        }

        // An empty row carries no real minimum, so it must not drag the
        // aggregate down to zero.
        if ($rowRequests > 0) {
            $rowMin = (int) ($summary->min_duration_ms ?? 0);

            $bucket['min_duration_ms'] = $first ? $rowMin : min((int) $bucket['min_duration_ms'], $rowMin);
        }

        $bucket['max_duration_ms'] = max((int) $bucket['max_duration_ms'], (int) ($summary->max_duration_ms ?? 0));
    }
}
