<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Endpoints\UsageEndpoint;
use Systemverk\LaravelApiUsage\Events\UsageEvent;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

return new class extends Migration
{
    public function getConnection(): ?string
    {
        return UsageConfig::databaseConnection();
    }

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tableName = UsageConfig::summariesTable();

        Schema::connection($this->getConnection())->create($tableName, function (Blueprint $table) use ($tableName) {
            $table->id();
            $table->string('period_type', 16);
            $table->date('period_start');

            $table->string('actor_type', UsageActor::MAX_TYPE_LENGTH);
            $table->string('actor_id', UsageActor::MAX_ID_LENGTH);

            // Empty string, not NULL, when no credential was used: the column is
            // part of the unique index below, and SQL treats every NULL as
            // distinct, which would defeat the upsert. The model maps it back to
            // null.
            $table->string('credential_id', UsageEvent::MAX_CREDENTIAL_ID_LENGTH)->default('');

            $table->string('endpoint_key', UsageEndpoint::MAX_KEY_LENGTH);
            $table->string('method', UsageEndpoint::MAX_METHOD_LENGTH);
            $table->string('route_name', UsageEndpoint::MAX_ROUTE_NAME_LENGTH)->nullable();
            $table->string('route_uri', UsageEndpoint::MAX_ROUTE_URI_LENGTH)->nullable();

            $table->unsignedBigInteger('total_requests')->default(0);
            $table->unsignedBigInteger('responses_1xx')->default(0);
            $table->unsignedBigInteger('responses_2xx')->default(0);
            $table->unsignedBigInteger('responses_3xx')->default(0);
            $table->unsignedBigInteger('responses_4xx')->default(0);
            $table->unsignedBigInteger('responses_5xx')->default(0);

            // Part of `responses_4xx`, not an addition to it. A 429 is a request
            // that was turned away before it did any work, which an application
            // metering usage must be able to tell apart from a 404 or a 422.
            $table->unsignedBigInteger('responses_429')->default(0);

            $table->unsignedBigInteger('total_duration_ms')->default(0);
            $table->unsignedInteger('min_duration_ms')->default(0);
            $table->unsignedInteger('max_duration_ms')->default(0);

            $table->timestamps();

            // The complete aggregation identity: period, who, with which
            // credential, against which endpoint. Consolidation upserts on exactly
            // this, and its `(period_type, period_start)` prefix also serves period
            // scans and pruning, so no further index is needed. Named explicitly
            // to stay under MySQL's 64-character identifier limit.
            $table->unique(
                [
                    'period_type', 'period_start', 'actor_type', 'actor_id',
                    'credential_id', 'endpoint_key',
                ],
                $tableName.'_identity_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(UsageConfig::summariesTable());
    }
};
