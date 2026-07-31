<?php

namespace Core\EventSourcing\Pii;

use Core\EventSourcing\Contracts\KeyRing;

/**
 * Trivial in-memory KeyRing implementation.
 *
 * For tests and prototyping only: keys live in a plain PHP array and are lost as
 * soon as the process ends, which effectively "forgets" every data subject on
 * every restart. Do not use this in production - bind Contracts\KeyRing to a
 * durable, persistent implementation instead
 */
class InMemoryKeyRing implements KeyRing
{
    protected array $keys = [];

    public function get(string $pii_id): ?string
    {
        return $this->keys[$pii_id] ?? null;
    }

    public function put(string $pii_id, string $key): void
    {
        $this->keys[$pii_id] = $key;
    }

    public function forget(string $pii_id): void
    {
        unset($this->keys[$pii_id]);
    }
}
