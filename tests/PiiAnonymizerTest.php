<?php

namespace Tests;

use Core\EventSourcing\Contracts\ContainsPersonalData;
use Core\EventSourcing\DomainEvent;
use Core\EventSourcing\Pii\InMemoryKeyRing;
use Core\EventSourcing\Pii\PiiAnonymizer;
use PHPUnit\Framework\TestCase;

class UserRegisteredForTest extends DomainEvent implements ContainsPersonalData
{
    protected $name = 'user.registered';
    public function piiFields(): array
    {
        return [
            'payload' => ['email', 'full_name'],
            'metadata' => ['ip_address'],
        ];
    }
}

class UserWithNestedPiiForTest extends DomainEvent implements ContainsPersonalData
{
    protected $name = 'user.registered.nested';
    public function piiFields(): array
    {
        return [
            'payload' => ['company.name'],
            'metadata' => ['location.country'],
        ];
    }
}

class UserWithCompanyForTest extends DomainEvent implements ContainsPersonalData
{
    protected $name = 'user.registered.company';
    public function piiFields(): array
    {
        return [
            'payload' => ['company.id'],
        ];
    }
}

class PiiAnonymizerTest extends TestCase
{
    protected function recordCreatedEvent(): UserRegisteredForTest
    {
        $event = new UserRegisteredForTest([
            'payload' => ['email' => 'jane@example.com', 'full_name' => 'Jane Doe', 'plan' => 'pro']
        ]);
        $event->setPiiId('subject-1');
        $event->setMetadata(['ip_address' => '127.0.0.1']);
        return $event;
    }

    public function test_seal_encrypts_declared_pii_fields_only()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = $this->recordCreatedEvent();

        $anonymizer->seal($event);

        $this->assertNotEquals('jane@example.com', $event->getPayload()->email);
        $this->assertNotEquals('Jane Doe', $event->getPayload()->full_name);
        $this->assertNotEquals('127.0.0.1', $event->getMetadata()->ip_address);
        // Non-PII fields are left untouched.
        $this->assertEquals('pro', $event->getPayload()->plan);
    }

    public function test_open_decrypts_back_while_key_exists()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = $this->recordCreatedEvent();

        $anonymizer->seal($event);
        $anonymizer->open($event);

        $this->assertEquals('jane@example.com', $event->getPayload()->email);
        $this->assertEquals('Jane Doe', $event->getPayload()->full_name);
        $this->assertEquals('127.0.0.1', $event->getMetadata()->ip_address);
    }

    public function test_forget_destroys_the_key_without_touching_the_event()
    {
        $keys = new InMemoryKeyRing();
        $anonymizer = new PiiAnonymizer($keys);
        $event = $this->recordCreatedEvent();

        $anonymizer->seal($event);
        $ciphertext = $event->getPayload()->email;

        $anonymizer->forget('subject-1');

        // The event log itself is untouched: ciphertext stays exactly as committed.
        $this->assertEquals($ciphertext, $event->getPayload()->email);

        // But it can no longer be decrypted: the key is gone, open() is a no-op.
        $anonymizer->open($event);
        $this->assertEquals($ciphertext, $event->getPayload()->email);
    }

    public function test_open_decrypts_a_plain_domain_event_reconstructed_from_raw_store_data()
    {
        $keys = new InMemoryKeyRing();
        $anonymizer = new PiiAnonymizer($keys);

        $sealed = $this->recordCreatedEvent();
        $anonymizer->seal($sealed);

        // Simulate what the event store actually does: reconstruct a plain DomainEvent
        // from the raw stored row, with no knowledge of UserRegisteredForTest.
        $reloaded = new DomainEvent($sealed->toArray());
        $this->assertNotInstanceOf(ContainsPersonalData::class, $reloaded);

        $anonymizer->open($reloaded);

        $this->assertEquals('jane@example.com', $reloaded->getPayload()->email);
        $this->assertEquals('Jane Doe', $reloaded->getPayload()->full_name);
        $this->assertEquals('127.0.0.1', $reloaded->getMetadata()->ip_address);
    }

    public function test_seal_and_open_handle_nested_field_paths()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = new UserWithNestedPiiForTest([
            'payload' => ['company' => ['name' => 'Acme Corp']],
        ]);
        $event->setPiiId('subject-2');
        $event->setMetadata(['location' => ['country' => 'US']]);

        $anonymizer->seal($event);

        $this->assertNotEquals('Acme Corp', $event->getPayload()->company['name']);
        $this->assertNotEquals('US', $event->getMetadata()->location['country']);

        $anonymizer->open($event);

        $this->assertEquals('Acme Corp', $event->getPayload()->company['name']);
        $this->assertEquals('US', $event->getMetadata()->location['country']);
    }

    public function test_nested_pii_field_does_not_touch_sibling_fields()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = new UserWithCompanyForTest([
            'payload' => [
                'company' => ['id' => 'acme-1', 'name' => 'Acme Corp', 'country' => 'US'],
                'plan' => 'pro',
            ],
        ]);
        $event->setPiiId('subject-3');

        $anonymizer->seal($event);

        $this->assertNotEquals('acme-1', $event->getPayload()->company['id']);
        $this->assertEquals('Acme Corp', $event->getPayload()->company['name']);
        $this->assertEquals('US', $event->getPayload()->company['country']);
        $this->assertEquals('pro', $event->getPayload()->plan);

        $anonymizer->open($event);

        $this->assertEquals('acme-1', $event->getPayload()->company['id']);
        $this->assertEquals('Acme Corp', $event->getPayload()->company['name']);
        $this->assertEquals('US', $event->getPayload()->company['country']);
    }

    public function test_events_without_pii_id_are_left_untouched()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = new UserRegisteredForTest([
            'payload' => ['email' => 'jane@example.com']
        ]);
        $anonymizer->seal($event);
        $this->assertEquals('jane@example.com', $event->getPayload()->email);
    }

    public function test_events_not_declaring_pii_fields_are_left_untouched()
    {
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());
        $event = new DomainEvent([
            'name' => 'plain.event',
            'payload' => ['email' => 'jane@example.com']
        ]);
        $event->setPiiId('subject-1');

        $anonymizer->seal($event);

        $this->assertEquals('jane@example.com', $event->getPayload()->email);
    }
}
