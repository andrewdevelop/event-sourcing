<?php

namespace Core\EventSourcing\Contracts;

/**
 * Stores per-data-subject encryption keys used for crypto-shredding (see GDPR_CCPA.md).
 * Applications must provide a persistent, durable implementation (e.g. backed by a
 * database, Redis, or a KMS/HSM) - losing a key early forgets a subject prematurely,
 * losing durability makes it impossible to ever truly forget anyone.
 */
interface KeyRing
{
    /**
     * Retrieve the encryption key for a data subject, or null if none exists
     * (either never issued, or the subject has been forgotten).
     * @param string $pii_id
     * @return string|null
     */
    public function get(string $pii_id): ?string;

    /**
     * Store the encryption key for a data subject.
     * @param string $pii_id
     * @param string $key
     * @return void
     */
    public function put(string $pii_id, string $key): void;

    /**
     * Irreversibly destroy a data subject's encryption key. Any event field that was
     * encrypted with this key becomes permanently unreadable, which satisfies GDPR/CCPA
     * erasure requests without mutating the append-only event log.
     * @param string $pii_id
     * @return void
     */
    public function forget(string $pii_id): void;
}
