<?php

namespace Systemverk\LaravelApiUsage\Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Systemverk\LaravelApiUsage\Support\SummaryBucket;

class SummaryBucketTest extends TestCase
{
    public function test_an_aggregated_row_becomes_a_bucket_with_integer_counters(): void
    {
        // Drivers return SUM() as strings, so the counters must be cast.
        $bucket = SummaryBucket::fromAggregate('day', '2026-06-17', (object) [
            'actor_type' => 'user', 'actor_id' => '1', 'actor_key' => 'user:1', 'credential_id' => null,
            'bucket_key' => 'user:1', 'endpoint_key' => 'GET:api.orders.index', 'method' => 'GET',
            'route_name' => 'api.orders.index', 'route_uri' => '/api/orders',
            'total_requests' => '9', 'responses_1xx' => '1', 'responses_2xx' => '2', 'responses_3xx' => '1',
            'responses_4xx' => '2', 'responses_5xx' => '2', 'total_duration_ms' => '140',
            'min_duration_ms' => '10', 'max_duration_ms' => '90',
        ], Carbon::parse('2026-06-18 00:00:00', 'UTC'));

        $this->assertSame(9, $bucket['total_requests']);
        $this->assertSame(2, $bucket['responses_5xx']);
        $this->assertSame(140, $bucket['total_duration_ms']);
        $this->assertSame(10, $bucket['min_duration_ms']);
        $this->assertSame(90, $bucket['max_duration_ms']);
        $this->assertSame('day', $bucket['period_type']);
        $this->assertSame('user:1|GET:api.orders.index', $bucket['bucket_key'].'|'.$bucket['endpoint_key']);
        $this->assertSame('api.orders.index', $bucket['route_name']);
    }

    public function test_it_folds_aggregated_rows_together(): void
    {
        $bucket = $this->bucket();

        SummaryBucket::addSummary($bucket, (object) [
            'total_requests' => 4, 'responses_2xx' => 4, 'total_duration_ms' => 200,
            'min_duration_ms' => 20, 'max_duration_ms' => 90,
        ]);
        SummaryBucket::addSummary($bucket, (object) [
            'total_requests' => 6, 'responses_5xx' => 6, 'total_duration_ms' => 300,
            'min_duration_ms' => 5, 'max_duration_ms' => 400,
        ]);

        $this->assertSame(10, $bucket['total_requests']);
        $this->assertSame(4, $bucket['responses_2xx']);
        $this->assertSame(6, $bucket['responses_5xx']);
        $this->assertSame(500, $bucket['total_duration_ms']);
        $this->assertSame(5, $bucket['min_duration_ms']);
        $this->assertSame(400, $bucket['max_duration_ms']);
    }

    public function test_an_empty_row_does_not_drag_the_minimum_down_to_zero(): void
    {
        $bucket = $this->bucket();

        SummaryBucket::addSummary($bucket, (object) [
            'total_requests' => 0, 'total_duration_ms' => 0, 'min_duration_ms' => 0, 'max_duration_ms' => 0,
        ]);
        SummaryBucket::addSummary($bucket, (object) [
            'total_requests' => 2, 'total_duration_ms' => 60, 'min_duration_ms' => 25, 'max_duration_ms' => 35,
        ]);

        $this->assertSame(25, $bucket['min_duration_ms']);
    }

    /**
     * @return array<string, mixed>
     */
    private function bucket(): array
    {
        return SummaryBucket::make('day', '2026-06-17', [
            'actor_key' => 'user:1',
            'bucket_key' => 'user:1',
            'endpoint_key' => 'GET:api.orders.index',
            'method' => 'GET',
        ], Carbon::parse('2026-06-18 00:00:00', 'UTC'));
    }
}
