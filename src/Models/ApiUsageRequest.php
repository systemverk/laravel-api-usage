<?php

namespace Systemverk\LaravelApiUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * A single recorded API request.
 *
 * This is the advanced API: prefer the query services behind the ApiUsage
 * facade for ordinary analytics. All timestamps are stored in UTC regardless
 * of the application timezone.
 *
 * @property int $id
 * @property \Illuminate\Support\Carbon $requested_at
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $actor_key
 * @property string|null $credential_id
 * @property string $bucket_key
 * @property string $method
 * @property string|null $route_name
 * @property string|null $route_uri
 * @property string $path
 * @property string $endpoint_key
 * @property int $status_code
 * @property int $duration_ms
 * @property string|null $ip_hash
 * @property string|null $user_agent
 * @property string|null $request_id
 */
class ApiUsageRequest extends Model
{
    protected $guarded = [];

    protected $casts = [
        'requested_at' => 'datetime',
        'status_code' => 'integer',
        'duration_ms' => 'integer',
    ];

    public function getTable(): string
    {
        return UsageConfig::requestsTable();
    }

    public function getConnectionName(): ?string
    {
        return UsageConfig::databaseConnection() ?? parent::getConnectionName();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeBetween(Builder $query, mixed $from, mixed $to): Builder
    {
        return $query->whereBetween('requested_at', [$from, $to]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeStatusClass(Builder $query, int $class): Builder
    {
        return $query->whereBetween('status_code', [$class * 100, $class * 100 + 99]);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForActor(Builder $query, UsageActor $actor): Builder
    {
        return $query->where('actor_type', $actor->type)->where('actor_id', $actor->id);
    }
}
