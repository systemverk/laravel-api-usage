<?php

namespace Systemverk\LaravelApiUsage\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Systemverk\LaravelApiUsage\Usage\UsageSummary;

class UsageSummaryTest extends TestCase
{
    public function test_an_empty_period_reports_zeros_and_no_durations(): void
    {
        $summary = UsageSummary::empty();

        $this->assertFalse($summary->hasUsage());
        $this->assertSame(0, $summary->totalRequests);
        $this->assertSame(0, $summary->serverErrors);
        $this->assertNull($summary->averageDurationMs);
        $this->assertNull($summary->minDurationMs);
        $this->assertNull($summary->maxDurationMs);
    }

    public function test_a_period_with_no_requests_never_divides_by_zero(): void
    {
        $summary = UsageSummary::fromTotals(['total_requests' => 0, 'total_duration_ms' => 0]);

        $this->assertSame(0.0, $summary->errorRate());
        $this->assertSame(0.0, $summary->serverErrorRate());
        $this->assertNull($summary->averageDurationMs);
    }

    public function test_it_builds_from_summed_columns(): void
    {
        $summary = UsageSummary::fromTotals([
            'total_requests' => 10,
            'responses_1xx' => 1,
            'responses_2xx' => 5,
            'responses_3xx' => 1,
            'responses_4xx' => 2,
            'responses_5xx' => 1,
            'total_duration_ms' => 500,
            'min_duration_ms' => 3,
            'max_duration_ms' => 210,
        ]);

        $this->assertTrue($summary->hasUsage());
        $this->assertSame(10, $summary->totalRequests);
        $this->assertSame(5, $summary->successfulRequests);
        $this->assertSame(2, $summary->clientErrors);
        $this->assertSame(1, $summary->serverErrors);
        $this->assertSame(500, $summary->totalDurationMs);
        $this->assertSame(50.0, $summary->averageDurationMs);
        $this->assertSame(3, $summary->minDurationMs);
        $this->assertSame(210, $summary->maxDurationMs);
    }

    public function test_the_error_rate_counts_client_and_server_errors(): void
    {
        $summary = UsageSummary::fromTotals([
            'total_requests' => 8,
            'responses_4xx' => 1,
            'responses_5xx' => 1,
            'total_duration_ms' => 80,
        ]);

        $this->assertSame(0.25, $summary->errorRate());
        $this->assertSame(0.125, $summary->serverErrorRate());
    }

    public function test_string_totals_from_the_database_are_coerced(): void
    {
        // Aggregate columns come back as strings on several drivers.
        $summary = UsageSummary::fromTotals([
            'total_requests' => '4',
            'responses_2xx' => '4',
            'total_duration_ms' => '40',
        ]);

        $this->assertSame(4, $summary->totalRequests);
        $this->assertSame(10.0, $summary->averageDurationMs);
    }

    public function test_it_exposes_an_array_shape(): void
    {
        $array = UsageSummary::fromTotals([
            'total_requests' => 2,
            'responses_5xx' => 1,
            'total_duration_ms' => 10,
        ])->toArray();

        $this->assertSame(2, $array['total_requests']);
        $this->assertSame(1, $array['server_errors']);
        $this->assertSame(0.5, $array['error_rate']);
    }
}
