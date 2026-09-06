<?php

namespace Systemverk\LaravelApiUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Support\BufferKeys;
use Systemverk\LaravelApiUsage\Support\UsageConfig;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

class RecordApiUsage
{
    /**
     * Attribute used to carry the start timestamp from handle() to terminate().
     */
    public const STARTED_AT = 'api_usage.started_at';

    public function __construct(private readonly UsageRecorder $recorder) {}

    /**
     * Handle an incoming request.
     *
     * The request is only timestamped here; the actual buffering happens in
     * terminate() so that neither actor resolution nor the two Redis round
     * trips sit on the critical path of the response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    /**
     * Buffer the request after the response has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! UsageRecorder::shouldRecord($request)) {
            return;
        }

        try {
            $connection = UsageConfig::redisConnection();

            if (! $this->redisConnectionIsConfigured($connection)) {
                return;
            }

            $startedAt = $request->attributes->get(self::STARTED_AT);
            $startedAt = is_float($startedAt) ? $startedAt : microtime(true);

            $event = $this->recorder->capture($request, $response, $startedAt);

            // A null actor means the application asked us not to record this
            // request — unauthenticated traffic with guest tracking disabled,
            // most commonly.
            if ($event === null) {
                return;
            }

            $serialized = json_encode($event->toPayload(), JSON_THROW_ON_ERROR);
            $key = BufferKeys::currentMinute();

            $redis = Redis::connection($connection);
            $redis->rpush($key, $serialized);
            $redis->expire($key, UsageConfig::redisTtlSeconds());
        } catch (\Throwable $exception) {
            $this->reportSilently($exception);
        }
    }

    /**
     * Usage tracking must never take an application down, so a missing or
     * misnamed Redis connection is treated as "disabled" rather than an error.
     */
    private function redisConnectionIsConfigured(string $connection): bool
    {
        return config("database.redis.{$connection}") !== null
            || config("database.redis.clusters.{$connection}") !== null;
    }

    private function reportSilently(\Throwable $exception): void
    {
        try {
            Log::warning('Failed to buffer an API usage event.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
