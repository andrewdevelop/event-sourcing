<?php

namespace Core\EventSourcing\Pii;

use Core\EventSourcing\Contracts\ContainsPersonalData;
use Core\EventSourcing\Contracts\KeyRing;
use Core\EventSourcing\DomainEvent;
use ArrayAccess;
use RuntimeException;

/**
 * Implements crypto-shredding for GDPR/CCPA erasure requests
 *
 * PII fields declared by an event (Contracts\ContainsPersonalData) are encrypted with
 * a key unique to the event's pii_id before the event is committed (seal), and can be
 * decrypted back for authorized reads as long as that key still exists (open).
 * Forgetting a data subject (forget) destroys their key: the ciphertext already stored
 * in the event log stays put - the append-only guarantee is preserved - but it becomes
 * permanently unreadable, which is the anonymization act itself.
 *
 * The event store only ever reconstructs a plain DomainEvent from raw rows - it has no
 * notion of the original concrete event class - so seal() also writes a manifest of
 * which fields it just encrypted into the event's own metadata. This makes a sealed
 * event self-describing: open() can decrypt it later from that manifest alone, even
 * when it can no longer see Contracts\ContainsPersonalData::piiFields().
 */
class PiiAnonymizer
{
    protected const CIPHER = 'aes-256-gcm';

    protected const MANIFEST_KEY = '__pii_fields';

    public function __construct(protected KeyRing $keys)
    {
    }

    /**
     * Encrypt an event's PII fields in place, issuing a key for its pii_id if needed.
     * No-op if the event doesn't declare PII fields or has no pii_id set.
     * @param DomainEvent $event
     * @return DomainEvent
     */
    public function seal(DomainEvent $event): DomainEvent
    {
        if (!$event instanceof ContainsPersonalData) return $event;

        $pii_id = $event->getPiiId();
        if (!$pii_id) return $event;

        $fields = $event->piiFields();
        if (empty($fields)) return $event;

        $key = $this->keys->get($pii_id) ?? $this->issueKey($pii_id);

        $this->transform($event, $fields, fn ($value) => $this->encrypt((string) $value, $key));

        $event->setMetadata([self::MANIFEST_KEY => $fields]);

        return $event;
    }

    /**
     * Decrypt an event's PII fields in place for an authorized read, as long as the
     * subject's key still exists. Once the subject has been forgotten, the fields are
     * left as ciphertext - there is nothing left to decrypt them with.
     *
     * Works both for events that still declare Contracts\ContainsPersonalData and for
     * plain DomainEvent instances reconstructed from raw store data, by falling back
     * to the manifest seal() left in the event's metadata.
     * @param DomainEvent $event
     * @return DomainEvent
     */
    public function open(DomainEvent $event): DomainEvent
    {
        $pii_id = $event->getPiiId();
        if (!$pii_id) return $event;

        $fields = $event instanceof ContainsPersonalData
            ? $event->piiFields()
            : ($event->getMetadata()->{self::MANIFEST_KEY} ?? null);

        if (empty($fields)) return $event;

        $key = $this->keys->get($pii_id);
        if (!$key) return $event;

        $this->transform($event, $fields, fn ($value) => $this->decrypt((string) $value, $key));

        return $event;
    }

    /**
     * Crypto-shred a data subject: irreversibly destroy their encryption key.
     * @param string $pii_id
     * @return void
     */
    public function forget(string $pii_id): void
    {
        $this->keys->forget($pii_id);
    }

    /**
     * Apply a transformation to every field named in the given manifest.
     * @param DomainEvent $event
     * @param array<string, string[]> $fields
     * @param callable(string): string $transform
     * @return void
     */
    protected function transform(DomainEvent $event, array $fields, callable $transform): void
    {
        foreach ($fields as $target => $names) {
            $bag = $event->{$target} ?? null;
            if (!$bag) continue;

            foreach ($names as $field) {
                $value = $this->readField($bag, $field, null);
                if ($value === null) continue;
                $this->writeField($bag, $field, $transform($value));
            }
        }
    }

    protected function issueKey(string $pii_id): string
    {
        $key = base64_encode(random_bytes(32));
        $this->keys->put($pii_id, $key);
        return $key;
    }

    protected function encrypt(string $plaintext, string $key): string
    {
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, base64_decode($key), OPENSSL_RAW_DATA, $iv, $tag);

        if ($ciphertext === false) {
            throw new RuntimeException('Failed to encrypt PII field.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    protected function decrypt(string $encoded, string $key): string
    {
        $raw = base64_decode($encoded);
        $iv_length = openssl_cipher_iv_length(self::CIPHER);

        $iv = substr($raw, 0, $iv_length);
        $tag = substr($raw, $iv_length, 16);
        $ciphertext = substr($raw, $iv_length + 16);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, base64_decode($key), OPENSSL_RAW_DATA, $iv, $tag);

        if ($plaintext === false) {
            throw new RuntimeException('Failed to decrypt PII field: wrong or destroyed key, or corrupted ciphertext.');
        }

        return $plaintext;
    }

    function readField($target, $key, $default = null)
    {
        if (is_null($key)) {
            return $target;
        }

        $key = is_array($key) ? $key : explode('.', $key);

        foreach ($key as $segment) {
            if (is_null($segment)) {
                return $target;
            }

            if ($target instanceof ArrayAccess) {
                if (! $target->offsetExists($segment)) {
                    return $default;
                }

                $target = $target[$segment];
            } elseif (is_array($target)) {
                if (! array_key_exists($segment, $target)) {
                    return $default;
                }

                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }

    function writeField(&$target, $key, $value, $overwrite = true)
    {
        $segments = is_array($key) ? $key : explode('.', $key);

        if (($segment = array_shift($segments)) === null) {
            return $target;
        }

        if ($target instanceof ArrayAccess || is_array($target)) {
            $exists = $target instanceof ArrayAccess
                ? $target->offsetExists($segment)
                : array_key_exists($segment, $target);

            if ($segments) {
                if (! $exists) {
                    $target[$segment] = [];
                }

                $inner = $target[$segment];
                data_set($inner, $segments, $value, $overwrite);
                $target[$segment] = $inner;
            } elseif ($overwrite || ! $exists) {
                $target[$segment] = $value;
            }
        } elseif (is_object($target)) {
            if ($segments) {
                if (! isset($target->{$segment})) {
                    $target->{$segment} = [];
                }

                data_set($target->{$segment}, $segments, $value, $overwrite);
            } elseif ($overwrite || ! isset($target->{$segment})) {
                $target->{$segment} = $value;
            }
        } else {
            $target = [];

            if ($segments) {
                data_set($target[$segment], $segments, $value, $overwrite);
            } elseif ($overwrite) {
                $target[$segment] = $value;
            }
        }

        return $target;
    }

}
