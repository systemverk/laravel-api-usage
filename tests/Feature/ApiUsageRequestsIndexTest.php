<?php

namespace Systemverk\LaravelApiUsage\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Tests\TestCase;

/**
 * The raw table is the one a quota or billing check recounts from, and that
 * recount happens while a caller waits. Its index is therefore behaviour, not
 * housekeeping, and column order is the whole of it.
 */
class ApiUsageRequestsIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_actor_period_index_covers_the_columns_a_recount_filters_on(): void
    {
        $this->assertSame(
            ['actor_type', 'actor_id', 'requested_at', 'status_code'],
            $this->index(UsageConfig::requestsTable().'_actor_period_index'),
        );
    }

    /**
     * A prefix of the index above answers nothing the longer one does not, and
     * this table takes an insert per API request.
     */
    public function test_the_redundant_actor_only_index_is_gone(): void
    {
        $this->assertNull($this->index('api_usage_requests_actor_type_actor_id_index'));
    }

    /**
     * The assertion that would catch a reordered index: the planner has to
     * reach it for an actor-scoped period count, which is the shape every
     * quota recount takes.
     */
    public function test_an_actor_scoped_period_count_is_planned_against_the_index(): void
    {
        $plan = DB::select(
            'explain query plan select count(*) from '.UsageConfig::requestsTable().
            ' where actor_type = ? and actor_id = ? and requested_at >= ? and requested_at < ?'.
            ' and status_code < 500',
            ['team', '1', '2026-09-01 00:00:00', '2026-10-01 00:00:00'],
        );

        $this->assertStringContainsString(
            UsageConfig::requestsTable().'_actor_period_index',
            implode(' ', array_column($plan, 'detail')),
        );
    }

    public function test_the_migration_restores_the_original_index_when_rolled_back(): void
    {
        $this->artisan('migrate:rollback', ['--step' => 1])->run();

        $this->assertNull($this->index(UsageConfig::requestsTable().'_actor_period_index'));
        $this->assertSame(
            ['actor_type', 'actor_id'],
            $this->index('api_usage_requests_actor_type_actor_id_index'),
        );
    }

    /**
     * The index's columns in their stored order, or null when it is absent.
     *
     * @return list<string>|null
     */
    private function index(string $name): ?array
    {
        foreach (Schema::getIndexes(UsageConfig::requestsTable()) as $index) {
            if ($index['name'] === $name) {
                return array_values($index['columns']);
            }
        }

        return null;
    }
}
