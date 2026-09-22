<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return UsageConfig::databaseConnection();
    }

    /**
     * Serves "what did this actor do during this period", which is the query a
     * quota or billing check runs against the raw rows.
     *
     * The original actor index carries no timestamp, so counting one month of
     * one actor's traffic reads every row that actor has inside the retention
     * window and discards the rest afterwards. The alternative the planner can
     * reach for, `(requested_at, status_code)`, scans the period across every
     * actor. Neither narrows to the rows actually being counted.
     *
     * Column order is equality, then range, then filter: the actor pins the
     * scan, `requested_at` bounds it, and `status_code` cannot narrow it at all
     * — a caller's billable-status rule is a negation, not a range. It is in
     * the index anyway to make the count index-only, which is what keeps a
     * recount off the row data entirely.
     *
     * The old index is dropped rather than kept: it is a strict prefix of this
     * one, so no query loses an access path, and this table takes an insert per
     * API request, where a redundant index is pure write cost.
     */
    public function up(): void
    {
        Schema::connection($this->getConnection())->table(UsageConfig::requestsTable(), function (Blueprint $table) {
            $table->index(
                ['actor_type', 'actor_id', 'requested_at', 'status_code'],
                $this->indexName(),
            );

            $table->dropIndex(['actor_type', 'actor_id']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->getConnection())->table(UsageConfig::requestsTable(), function (Blueprint $table) {
            $table->index(['actor_type', 'actor_id']);

            $table->dropIndex($this->indexName());
        });
    }

    /**
     * Named explicitly because the generated name would be 69 characters and
     * every column added to the index makes it longer, while MySQL stops at 64.
     * Derived from the configured table name rather than hardcoded, so two
     * installations sharing a Postgres schema cannot collide.
     */
    private function indexName(): string
    {
        return UsageConfig::requestsTable().'_actor_period_index';
    }
};
