<?php

namespace Systemverk\LaravelApiUsage\Endpoints;

/**
 * The logical endpoint a request was made against.
 *
 * Concrete paths ("/api/orders/123") make poor analytics dimensions because
 * every resource id fragments the data. The endpoint key prefers the Laravel
 * route name, then the route URI, and only falls back to the raw path when the
 * request never matched a route at all.
 */
final readonly class UsageEndpoint
{
    public const MAX_KEY_LENGTH = 191;

    public const MAX_ROUTE_NAME_LENGTH = 255;

    public const MAX_ROUTE_URI_LENGTH = 255;

    public const MAX_PATH_LENGTH = 1024;

    public const MAX_METHOD_LENGTH = 16;

    public function __construct(
        public string $method,
        public ?string $routeName,
        public ?string $routeUri,
        public string $path,
    ) {}

    public static function make(string $method, ?string $routeName, ?string $routeUri, string $path): self
    {
        $routeUri = self::normalizeOptional($routeUri, self::MAX_ROUTE_URI_LENGTH);

        return new self(
            self::normalizeMethod($method),
            self::normalizeOptional($routeName, self::MAX_ROUTE_NAME_LENGTH),
            $routeUri === null ? null : self::withLeadingSlash($routeUri, self::MAX_ROUTE_URI_LENGTH),
            self::withLeadingSlash(trim($path), self::MAX_PATH_LENGTH),
        );
    }

    /**
     * "METHOD:identity", where identity is the most canonical one available.
     */
    public function key(): string
    {
        $identity = $this->routeName ?? $this->routeUri ?? $this->path;

        return mb_substr($this->method.':'.$identity, 0, self::MAX_KEY_LENGTH);
    }

    private static function normalizeMethod(string $method): string
    {
        $method = mb_strtoupper(trim($method));

        return mb_substr($method === '' ? 'GET' : $method, 0, self::MAX_METHOD_LENGTH);
    }

    /**
     * Truncation happens after the slash is added, so the result always fits the
     * column it is written to.
     */
    private static function withLeadingSlash(string $value, int $length): string
    {
        return mb_substr('/'.ltrim($value, '/'), 0, $length);
    }

    private static function normalizeOptional(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }
}
