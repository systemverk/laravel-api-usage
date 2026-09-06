<?php

namespace Systemverk\LaravelApiUsage\Support;

use Illuminate\Support\Carbon;

/**
 * Shared shape of an aggregated usage row.
 *
 * Both consolidation commands build the same rows — one from raw requests, one
 * from daily summaries — so the column list lives in a single place.
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
        'actor_type',
        'actor_id',
        'actor_key',
        'credential_id',
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
            'actor_type' => $identity['actor_type'] ?? null,
            'actor_id' => $identity['actor_id'] ?? null,
            'actor_key' => (string) ($identity['actor_key'] ?? ''),
            'credential_id' => $identity['credential_id'] ?? null,
            'bucket_key' => (string) ($identity['bucket_key'] ?? ''),
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
     * Fold one raw request into a bucket.
     *
     * Codes outside 100-599 still count towards the total so that it always
     * reflects the number of requests, even when a class bucket is missing.
     *
     * @param  array<string, mixed>  $bucket
     */
    public static function addRequest(array &$bucket, int $statusCode, int $durationMs): void
    {
        $durationMs = max(0, $durationMs);
        $first = $bucket['total_requests'] === 0;

        $bucket['total_requests']++;
        $bucket['total_duration_ms'] += $durationMs;
        $bucket['min_duration_ms'] = $first ? $durationMs : min((int) $bucket['min_duration_ms'], $durationMs);
        $bucket['max_duration_ms'] = max((int) $bucket['max_duration_ms'], $durationMs);

        $column = match (intdiv($statusCode, 100)) {
            1 => 'responses_1xx',
            2 => 'responses_2xx',
            3 => 'responses_3xx',
            4 => 'responses_4xx',
            5 => 'responses_5xx',
            default => null,
        };

        if ($column !== null) {
            $bucket[$column]++;
        }
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
