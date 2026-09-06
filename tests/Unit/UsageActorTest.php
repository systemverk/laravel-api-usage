<?php

namespace Systemverk\LaravelApiUsage\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Systemverk\LaravelApiUsage\Actors\UsageActor;

class UsageActorTest extends TestCase
{
    public function test_it_normalizes_integer_ids_to_strings(): void
    {
        $actor = UsageActor::user(42);

        $this->assertSame('42', $actor->id);
        $this->assertSame('user', $actor->type);
        $this->assertSame('user:42', $actor->key());
    }

    public function test_it_keeps_string_ids_intact(): void
    {
        $actor = UsageActor::user('9d5f1a2e-0000-4000-8000-000000000000');

        $this->assertSame('9d5f1a2e-0000-4000-8000-000000000000', $actor->id);
        $this->assertSame('user:9d5f1a2e-0000-4000-8000-000000000000', $actor->key());
    }

    public function test_any_actor_type_is_supported(): void
    {
        $this->assertSame('organization:12', UsageActor::organization(12)->key());
        $this->assertSame('tenant:acme', UsageActor::make('tenant', 'acme')->key());
        $this->assertSame('api_key:abc123', UsageActor::make('api_key', 'abc123')->key());
        $this->assertSame('service_account:billing', UsageActor::make('service_account', 'billing')->key());
    }

    public function test_the_type_is_lowercased_so_one_actor_never_splits_in_two(): void
    {
        $this->assertSame('organization:12', UsageActor::make('Organization', 12)->key());
        $this->assertSame(UsageActor::make('USER', 5)->key(), UsageActor::user(5)->key());
    }

    public function test_surrounding_whitespace_is_trimmed(): void
    {
        $this->assertSame('tenant:acme', UsageActor::make('  tenant ', ' acme ')->key());
    }

    public function test_the_guest_actor_has_a_key_of_its_own(): void
    {
        $guest = UsageActor::guest();

        $this->assertSame('guest', $guest->key());
        $this->assertSame('guest', $guest->type);
        $this->assertTrue($guest->isGuest());
        $this->assertFalse(UsageActor::user(1)->isGuest());
    }

    public function test_an_empty_type_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsageActor::make('   ', 42);
    }

    public function test_an_empty_id_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        UsageActor::make('user', '  ');
    }

    public function test_labels_are_optional_and_trimmed(): void
    {
        $this->assertNull(UsageActor::user(1)->label);
        $this->assertNull(UsageActor::user(1, '  ')->label);
        $this->assertSame('Acme Inc', UsageActor::organization(1, ' Acme Inc ')->label);
    }

    public function test_oversized_values_are_truncated_to_the_column_widths(): void
    {
        $actor = UsageActor::make(str_repeat('t', 200), str_repeat('i', 200), str_repeat('l', 500));

        $this->assertSame(UsageActor::MAX_TYPE_LENGTH, mb_strlen($actor->type));
        $this->assertSame(UsageActor::MAX_ID_LENGTH, mb_strlen($actor->id));
        $this->assertSame(UsageActor::MAX_LABEL_LENGTH, mb_strlen((string) $actor->label));
    }

    public function test_it_can_be_rebuilt_from_stored_columns(): void
    {
        $actor = UsageActor::fromStored('organization', '42');

        $this->assertNotNull($actor);
        $this->assertSame('organization:42', $actor->key());

        $this->assertNull(UsageActor::fromStored(null, '42'));
        $this->assertNull(UsageActor::fromStored('organization', null));
        $this->assertNull(UsageActor::fromStored('', ''));
    }
}
