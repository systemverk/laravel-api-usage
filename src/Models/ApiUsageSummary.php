<?php

namespace Systemverk\LaravelApiUsage\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Systemverk\LaravelApiUsage\Actors\UsageActor;
use Systemverk\LaravelApiUsage\Support\UsageConfig;

/**
 * Aggregated usage for one actor, credential and endpoint within one period.
 *
 * This is the advanced API: prefer the query services behind the ApiUsage
 * facade for ordinary analytics.
 *
 * @property int $id
 * @property string $period_type
 * @property \Illuminate\Support\Carbon $period_start
 * @property string|null $actor_type
 * @property string|null $actor_id
 * @property string $actor_key
 * @property string|null $credential_id
 * @property string $bucket_key
 * @property string $endpoint_key
 * @property string $method
 * @property string|null $route_name
 * @property string|null $route_uri
 * @property int $total_requests
 * @property int $responses_1xx
 * @property int $responses_2xx
 * @property int $responses_3xx
 * @property int $responses_4xx
 * @property int $responses_5xx
 * @property int $total_duration_ms
 * @property int $min_duration_ms
 * @property int $max_duration_ms
 */
class ApiUsageSummary extends Model
{
    public const PERIOD_DAY = 'day';

    public const PERIOD_MONTH = 'month';

    protected $guarded = [];

    protected $casts = [
        'period_start' => 'date',
        'actor_key' => 'string',
        'bucket_key' => 'string',
        'total_requests' => 'integer',
        'responses_1xx' => 'integer',
        'responses_2xx' => 'integer',
        'responses_3xx' => 'integer',
        'responses_4xx' => 'integer',
        'responses_5xx' => 'integer',
        'total_duration_ms' => 'integer',
        'min_duration_ms' => 'integer',
        'max_duration_ms' => 'integer',
    ];

    public function getTable(): string
    {
        return UsageConfig::summariesTable();
    }

    public function getConnectionName(): ?string
    {
        return UsageConfig::databaseConnection() ?? parent::getConnectionName();
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeDaily(Builder $query): Builder
    {
        return $query->where('period_type', self::PERIOD_DAY);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeMonthly(Builder $query): Builder
    {
        return $query->where('period_type', self::PERIOD_MONTH);
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
