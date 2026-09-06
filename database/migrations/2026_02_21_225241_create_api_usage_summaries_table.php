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

            $table->string('actor_type', UsageActor::MAX_TYPE_LENGTH)->nullable();
            $table->string('actor_id', UsageActor::MAX_ID_LENGTH)->nullable();
            $table->string('actor_key', UsageEvent::MAX_BUCKET_KEY_LENGTH);
            $table->string('credential_id', UsageEvent::MAX_CREDENTIAL_ID_LENGTH)->nullable();

            // The actor key plus the credential, if any. Always non-null so it
            // can carry the unique index: a nullable column there would defeat
            // the upsert, since SQL treats every NULL as distinct.
            $table->string('bucket_key', UsageEvent::MAX_BUCKET_KEY_LENGTH);

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

            $table->unsignedBigInteger('total_duration_ms')->default(0);
            $table->unsignedInteger('min_duration_ms')->default(0);
            $table->unsignedInteger('max_duration_ms')->default(0);

            $table->timestamps();

            // The complete aggregation identity: period, who, with which key,
            // against which endpoint. Consolidation upserts on exactly this.
            $table->unique(
                ['period_type', 'period_start', 'bucket_key', 'endpoint_key'],
                $tableName.'_identity_unique'
            );

            // Serves period scans and the actor/endpoint analytics queries.
            $table->index(['period_type', 'period_start']);
            $table->index(['period_type', 'period_start', 'actor_type', 'actor_id']);
            $table->index(['period_type', 'period_start', 'endpoint_key']);
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
