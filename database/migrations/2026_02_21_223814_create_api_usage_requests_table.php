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
        Schema::connection($this->getConnection())->create(UsageConfig::requestsTable(), function (Blueprint $table) {
            $table->id();
            $table->timestamp('requested_at')->index();

            // Actor identifiers are always strings, so integer and UUID keys
            // produce the same storage shape.
            $table->string('actor_type', UsageActor::MAX_TYPE_LENGTH)->nullable();
            $table->string('actor_id', UsageActor::MAX_ID_LENGTH)->nullable();
            $table->string('actor_key', UsageEvent::MAX_BUCKET_KEY_LENGTH)->index();
            $table->string('credential_id', UsageEvent::MAX_CREDENTIAL_ID_LENGTH)->nullable()->index();
            $table->string('bucket_key', UsageEvent::MAX_BUCKET_KEY_LENGTH);

            $table->string('method', UsageEndpoint::MAX_METHOD_LENGTH);
            $table->string('route_name', UsageEndpoint::MAX_ROUTE_NAME_LENGTH)->nullable();
            $table->string('route_uri', UsageEndpoint::MAX_ROUTE_URI_LENGTH)->nullable();
            $table->string('path', UsageEndpoint::MAX_PATH_LENGTH);
            $table->string('endpoint_key', UsageEndpoint::MAX_KEY_LENGTH)->index();

            $table->unsignedSmallInteger('status_code')->index();
            $table->unsignedInteger('duration_ms');

            $table->string('ip_hash', UsageEvent::MAX_IP_HASH_LENGTH)->nullable()->index();
            $table->string('user_agent', UsageEvent::MAX_USER_AGENT_LENGTH)->nullable();
            $table->string('request_id', UsageEvent::MAX_REQUEST_ID_LENGTH)->nullable()->index();
            $table->timestamps();

            // Serves the daily consolidation scan and status-code breakdowns.
            $table->index(['requested_at', 'status_code']);

            // Serves "everything this actor did", the most common ad-hoc query
            // against the raw table.
            $table->index(['actor_type', 'actor_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection($this->getConnection())->dropIfExists(UsageConfig::requestsTable());
    }
};
