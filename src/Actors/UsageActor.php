<?php

namespace Systemverk\LaravelApiUsage\Actors;

use InvalidArgumentException;

/**
 * An entity API usage can be attributed to.
 *
 * Deliberately decoupled from Eloquent, Authenticatable and any authentication
 * package: an actor may be a user, an organization, a tenant, an API key, a
 * service account or anything else the application can name.
 *
 * Identifiers are normalized to strings so that integer and UUID keys produce
 * the same storage shape and the same actor key.
 */
final readonly class UsageActor
{
    public const GUEST_TYPE = 'guest';

    public const MAX_TYPE_LENGTH = 64;

    public const MAX_ID_LENGTH = 64;

    public const MAX_LABEL_LENGTH = 255;

    /**
     * The key of an actor with no identity of its own.
     */
    public const GUEST_KEY = 'guest';

    /**
     * @param  string  $type  Lowercase actor type, e.g. "user" or "organization".
     * @param  string  $id  Non-empty identifier, normalized to a string.
     * @param  string|null  $label  Human-readable name. Never persisted — see the
     *                              privacy section of the README.
     */
    public function __construct(
        public string $type,
        public string $id,
        public ?string $label = null,
    ) {
        if ($type === '') {
            throw new InvalidArgumentException('An actor type cannot be empty.');
        }

        if ($id === '') {
            throw new InvalidArgumentException('An actor id cannot be empty.');
        }
    }

    public static function make(string $type, string|int $id, ?string $label = null): self
    {
        return new self(
            self::normalizeType($type),
            self::normalizeId($id),
            self::normalizeLabel($label),
        );
    }

    public static function user(string|int $id, ?string $label = null): self
    {
        return self::make('user', $id, $label);
    }

    public static function organization(string|int $id, ?string $label = null): self
    {
        return self::make('organization', $id, $label);
    }

    public static function guest(): self
    {
        return new self(self::GUEST_TYPE, self::GUEST_TYPE);
    }

    /**
     * Rebuild an actor from the columns it was stored in.
     */
    public static function fromStored(?string $type, ?string $id): ?self
    {
        if ($type === null || $type === '' || $id === null || $id === '') {
            return null;
        }

        return new self($type, $id);
    }

    /**
     * Stable, groupable identity: "type:id", or plain "guest".
     */
    public function key(): string
    {
        return $this->isGuest() ? self::GUEST_KEY : $this->type.':'.$this->id;
    }

    public function isGuest(): bool
    {
        return $this->type === self::GUEST_TYPE;
    }

    private static function normalizeType(string $type): string
    {
        // Lowercased so that "User" and "user" never split one actor in two.
        $type = mb_strtolower(trim($type));

        if ($type === '') {
            throw new InvalidArgumentException('An actor type cannot be empty.');
        }

        return mb_substr($type, 0, self::MAX_TYPE_LENGTH);
    }

    private static function normalizeId(string|int $id): string
    {
        $id = trim((string) $id);

        if ($id === '') {
            throw new InvalidArgumentException('An actor id cannot be empty.');
        }

        return mb_substr($id, 0, self::MAX_ID_LENGTH);
    }

    private static function normalizeLabel(?string $label): ?string
    {
        if ($label === null) {
            return null;
        }

        $label = trim($label);

        return $label === '' ? null : mb_substr($label, 0, self::MAX_LABEL_LENGTH);
    }
}
