<?php

namespace Systemverk\LaravelApiUsage\Tests\Unit;

use Illuminate\Support\Carbon;
use PHPUnit\Framework\TestCase;
use Systemverk\LaravelApiUsage\Support\SummaryBucket;

class SummaryBucketTest extends TestCase
{
    public function test_it_counts_every_status_class(): void
    {
        $bucket = $this->bucket();

        foreach ([100, 200, 201, 301, 404, 422, 500, 503, 799] as $status) {
            SummaryBucket::addRequest($bucket, $status, 10);
        }

        $this->assertSame(9, $bucket['total_requests']);
        $this->assertSame(1, $bucket['responses_1xx']);
        $this->assertSame(2, $bucket['responses_2xx']);
        $this->assertSame(1, $bucket['responses_3xx']);
        $this->assertSame(2, $bucket['responses_4xx']);
        $this->assertSame(2, $bucket['responses_5xx']);
    }

    public function test_a_status_outside_the_known_classes_still_counts_towards_the_total(): void
    {
        $bucket = $this->bucket();

        SummaryBucket::addRequest($bucket, 799, 5);

        $this->assertSame(1, $bucket['total_requests']);
        $this->assertSame(0, array_sum([
            $bucket['responses_1xx'], $bucket['responses_2xx'], $bucket['responses_3xx'],
            $bucket['responses_4xx'], $bucket['responses_5xx'],
        ]));
    }

    public function test_it_tracks_duration_totals_and_extremes(): void
    {
        $bucket = $this->bucket();

        foreach ([40, 10, 90] as $duration) {
            SummaryBucket::addRequest($bucket, 200, $duration);
        }

        $this->assertSame(140, $bucket['total_duration_ms']);
        $this->assertSame(10, $bucket['min_duration_ms']);
        $this->assertSame(90, $bucket['max_duration_ms']);
    }

    public function test_the_first_request_sets_the_minimum_rather_than_leaving_it_at_zero(): void
    {
        $bucket = $this->bucket();

        SummaryBucket::addRequest($bucket, 200, 55);

        $this->assertSame(55, $bucket['min_duration_ms']);
    }

    public function test_negative_durations_are_clamped(): void
    {
        $bucket = $this->bucket();

        SummaryBucket::addRequest($bucket, 200, -17);

        $this->assertSame(0, $bucket['total_duration_ms']);
        $this->assertSame(0, $bucket['min_duration_ms']);
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
