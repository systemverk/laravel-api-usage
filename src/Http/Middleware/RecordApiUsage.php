<?php

namespace Systemverk\LaravelApiUsage\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Systemverk\LaravelApiUsage\Contracts\StoresUsageEvents;
use Systemverk\LaravelApiUsage\Support\UsageRecorder;

class RecordApiUsage
{
    /**
     * Attribute used to carry the start timestamp from handle() to terminate().
     */
    public const STARTED_AT = 'api_usage.started_at';

    /**
     * A request time older than this is treated as stale rather than real.
     */
    private const MAX_PLAUSIBLE_DURATION_SECONDS = 300;

    public function __construct(
        private readonly UsageRecorder $recorder,
        private readonly StoresUsageEvents $store,
    ) {}

    /**
     * Handle an incoming request.
     *
     * The request is only timestamped here; the actual buffering happens in
     * terminate() so that neither actor resolution nor the write to Redis or the
     * database sits on the critical path of the response.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set(self::STARTED_AT, microtime(true));

        return $next($request);
    }

    /**
     * Store the request after the response has been sent to the client.
     */
    public function terminate(Request $request, Response $response): void
    {
        if (! UsageRecorder::shouldRecord($request)) {
            return;
        }

        try {
            if (! $this->store->isAvailable()) {
                return;
            }

            $event = $this->recorder->capture($request, $response, self::startedAt($request));

            // A null actor means the application asked us not to record this
            // request — unauthenticated traffic with guest tracking disabled,
            // most commonly.
            if ($event === null) {
                return;
            }

            $this->store->store($event);
        } catch (\Throwable $exception) {
            $this->reportSilently($exception);
        }
    }

    /**
     * When the request started, as early as it can be known.
     *
     * The web server's request time covers bootstrap and every middleware that
     * ran before this one, and it is still there when an earlier middleware (a
     * throttle, say) answered without handle() ever running. A value from the
     * future or from long ago is not a request time at all — Octane workers
     * keep process-level timestamps for their whole life — so it is ignored in
     * favour of the timestamp taken in handle().
     */
    public static function startedAt(Request $request): float
    {
        $now = microtime(true);
        $server = $request->server->get('REQUEST_TIME_FLOAT');

        if (is_float($server) && $server <= $now && $now - $server <= self::MAX_PLAUSIBLE_DURATION_SECONDS) {
            return $server;
        }

        $handled = $request->attributes->get(self::STARTED_AT);

        return is_float($handled) ? $handled : $now;
    }

    private function reportSilently(\Throwable $exception): void
    {
        try {
            Log::warning('Failed to record an API usage event.', [
                'exception' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        } catch (\Throwable) {
            //
        }
    }
}
