<?php

namespace Core\EventSourcing\Contracts;

/**
 * Implemented by concrete DomainEvent subclasses that carry personal data (PII).
 * Declares which payload/metadata fields must be encrypted at rest and destroyed
 * on erasure.
 */
interface ContainsPersonalData
{
    /**
     * Fields that hold personal data, keyed by target ("payload" or "metadata").
     *
     * Example: ['payload' => ['email', 'full_name'], 'metadata' => ['ip_address']]
     *
     * @return array<string, string[]>
     */
    public function piiFields(): array;
}
