<?php

namespace Core\EventSourcing\Pii;

use Core\EventSourcing\Contracts\KeyRing;

class DeveloperKeyRing implements KeyRing
{
    private string $key = 'dev';

    public function withKey(string $ns): KeyRing
    {
        $this->key = $ns;
        return $this;
    }

    /**
     * @inheritDoc
     */
    public function get(string $pii_id): ?string
    {
        return sha1($this->key . $pii_id);
    }

    /**
     * @inheritDoc
     */
    public function put(string $pii_id, string $key): void
    {
        return;
    }

    /**
     * @inheritDoc
     */
    public function forget(string $pii_id): void
    {
        return;
    }
}