<?php

namespace Systemverk\LaravelApiUsage\Tests\Support;

use Closure;
use Illuminate\Redis\Connections\Connection;

/**
 * Minimal in-memory stand-in for the subset of Redis the package uses.
 *
 * It models the semantics the flush command actually depends on — renamenx
 * returning false for a missing key, SET NX refusing to overwrite, TTL
 * bookkeeping — so the command's control flow is exercised for real rather
 * than against a mock that always agrees.
 */
class FakeRedisConnection extends Connection
{
    /** @var array<string, mixed> */
    public array $store = [];

    /** @var array<string, int> */
    public array $ttls = [];

    /** @var array<int, string> */
    public array $calls = [];

    /**
     * Method names that should throw on the next call, keyed by method.
     *
     * @var array<string, \Throwable>
     */
    private array $failures = [];

    public function failOn(string $method, \Throwable $exception): void
    {
        $this->failures[$method] = $exception;
    }

    /**
     * Pub/sub is not used by the package; required by the base class.
     *
     * @param  array<int, string>|string  $channels
     */
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void
    {
        //
    }

    /**
     * Illuminate's Connection::command() forwards its parameters verbatim to
     * the underlying client, so this double stands in for a *predis* client:
     * positional SET options, and members passed one per argument.
     *
     * phpredis differs on both counts, which is why the flush command branches
     * on the connection class rather than trusting __call. Nothing in this file
     * can prove the phpredis branch — only a real phpredis connection can — so
     * do not read a green suite here as evidence that both clients work.
     *
     * @param  array<int, mixed>  $parameters
     */
    public function command($method, array $parameters = []): mixed
    {
        return $this->{$method}(...$parameters);
    }

    public function rpush(string $key, string ...$values): int
    {
        $this->guard('rpush');

        $list = $this->list($key);
        array_push($list, ...$values);
        $this->store[$key] = $list;

        return count($list);
    }

    public function llen(string $key): int
    {
        $this->guard('llen');

        return count($this->list($key));
    }

    /**
     * @return array<int, string>
     */
    public function lrange(string $key, int $start, int $stop): array
    {
        $this->guard('lrange');

        $list = $this->list($key);

        if ($stop < 0) {
            $stop = count($list) + $stop;
        }

        return array_slice($list, $start, $stop - $start + 1);
    }

    public function renamenx(string $from, string $to): bool
    {
        $this->guard('renamenx');

        if (! array_key_exists($from, $this->store)) {
            // Real Redis reports "ERR no such key"; predis surfaces that as an
            // exception, which is exactly the case the command must survive.
            throw new \RuntimeException('ERR no such key');
        }

        if (array_key_exists($to, $this->store)) {
            return false;
        }

        $this->store[$to] = $this->store[$from];
        unset($this->store[$from]);

        if (isset($this->ttls[$from])) {
            $this->ttls[$to] = $this->ttls[$from];
            unset($this->ttls[$from]);
        }

        return true;
    }

    public function set(string $key, mixed $value, ?string $expireResolution = null, ?int $expireTtl = null, ?string $flag = null): bool
    {
        $this->guard('set');

        if ($flag === 'NX' && array_key_exists($key, $this->store)) {
            return false;
        }

        $this->store[$key] = $value;

        if ($expireResolution === 'EX' && $expireTtl !== null) {
            $this->ttls[$key] = $expireTtl;
        }

        return true;
    }

    public function del(string ...$keys): int
    {
        $this->guard('del');

        $deleted = 0;

        foreach ($keys as $key) {
            if (array_key_exists($key, $this->store)) {
                $deleted++;
            }

            unset($this->store[$key], $this->ttls[$key]);
        }

        return $deleted;
    }

    public function expire(string $key, int $seconds): bool
    {
        $this->guard('expire');

        if (! array_key_exists($key, $this->store)) {
            return false;
        }

        $this->ttls[$key] = $seconds;

        return true;
    }

    /**
     * Members are variadic strings, matching phpredis.
     *
     * Predis also accepts a single array, which made an array argument look
     * harmless — but phpredis casts one to the literal string "Array", quietly
     * filling the registry with a member that resolves to nothing. Typing this
     * strictly turns that into a failing test rather than a silent data loss.
     */
    public function sadd(string $key, string ...$members): int
    {
        $this->guard('sadd');

        $set = $this->set_($key);
        $added = 0;

        foreach ($members as $member) {
            if (! in_array($member, $set, true)) {
                $set[] = $member;
                $added++;
            }
        }

        $this->store[$key] = $set;

        return $added;
    }

    public function srem(string $key, string ...$members): int
    {
        $this->guard('srem');

        $set = $this->set_($key);
        $before = count($set);
        $set = array_values(array_diff($set, $members));
        $this->store[$key] = $set;

        return $before - count($set);
    }

    /**
     * @return array<int, string>
     */
    public function smembers(string $key): array
    {
        $this->guard('smembers');

        return $this->set_($key);
    }

    /**
     * @return array<int, string>
     */
    private function list(string $key): array
    {
        $value = $this->store[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    /**
     * @return array<int, string>
     */
    private function set_(string $key): array
    {
        $value = $this->store[$key] ?? [];

        return is_array($value) ? array_values($value) : [];
    }

    private function guard(string $method): void
    {
        $this->calls[] = $method;

        if (isset($this->failures[$method])) {
            $exception = $this->failures[$method];
            unset($this->failures[$method]);

            throw $exception;
        }
    }
}

