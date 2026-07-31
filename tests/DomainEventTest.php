<?php

namespace Tests;

use Carbon\Carbon;
use Core\EventSourcing\Data;
use Core\EventSourcing\DomainEvent;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class DomainEventTest extends TestCase
{
    public function test_it_throws_exception_if_name_is_missing()
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Event name not defined.');

        new DomainEvent([]);
    }

    public function test_it_can_be_instantiated_with_data()
    {
        $data = [
            'name' => 'user.created',
            'version' => 2,
            'aggregate_id' => 'agg-123',
            'aggregate_type' => 'User',
            'aggregate_version' => 1,
        ];

        $event = new DomainEvent($data);

        $this->assertEquals('user.created', $event->getName());
        $this->assertEquals(2, $event->getVersion());
        $this->assertEquals('agg-123', $event->getAggregateId());
        $this->assertEquals('User', $event->getAggregateType());
        $this->assertEquals(1, $event->getAggregateVersion());
    }

    public function test_it_auto_generates_id_if_not_provided()
    {
        $event = new DomainEvent(['name' => 'test']);
        $event->setId('');

        $this->assertNotEmpty($event->getId());
        $this->assertIsString($event->getId());
    }

    public function test_magic_getters_and_setters()
    {
        $event = new DomainEvent(['name' => 'test']);
        
        $event->aggregate_id = 'magic-id';
        $this->assertEquals('magic-id', $event->getAggregateId());
        $this->assertEquals('magic-id', $event->aggregate_id);

        // Accessing undefined property should return null safely via magic getter
        $this->assertNull($event->undefined_property);
    }

    public function test_payload_and_metadata_are_cast_to_data_object()
    {
        $event = new DomainEvent(['name' => 'test']);

        $payloadArray = ['foo' => 'bar'];
        $event->setPayload($payloadArray);

        $this->assertInstanceOf(Data::class, $event->getPayload());
        $this->assertEquals('bar', $event->getPayload()->foo);

        $metadataJson = '{"meta_key":"meta_val"}';
        $event->setMetadata($metadataJson);

        $this->assertInstanceOf(Data::class, $event->getMetadata());
        $this->assertEquals('meta_val', $event->getMetadata()->meta_key);
    }

    public function test_created_at_is_set_to_carbon_instance()
    {
        $event = new DomainEvent(['name' => 'test']);

        // Test with null (should default to now)
        $event->setCreatedAt(null);
        $this->assertInstanceOf(\DateTimeInterface::class, $event->getCreatedAt());

        // Test with string
        $event->setCreatedAt('2024-01-01 10:00:00');
        $this->assertEquals('2024-01-01 10:00:00.000000', $event->getCreatedAt()->format('Y-m-d H:i:s.u'));

        // Test with integer timestamp
        $timestamp = time();
        $event->setCreatedAt($timestamp);
        $this->assertEquals($timestamp, $event->getCreatedAt()->getTimestamp());
    }

    public function test_pii_id_is_optional_and_serialized()
    {
        $event = new DomainEvent(['name' => 'user.registered']);

        $this->assertNull($event->getPiiId());

        $event->setPiiId('subject-123');
        $this->assertEquals('subject-123', $event->getPiiId());
        $this->assertEquals('subject-123', $event->pii_id);
        $this->assertEquals('subject-123', $event->toArray()['pii_id']);
    }

    public function test_serialization_methods()
    {
        $event = new DomainEvent([
            'name' => 'test.event',
            'id' => '12345',
            'created_at' => '2024-01-01 12:00:00'
        ]);
        
        $event->setPayload(['key' => 'value']);
        $event->setMetadata(['foo' => 'bar']);

        $array = $event->toArray();
        $this->assertIsArray($array);
        $this->assertEquals('test.event', $array['name']);
        $this->assertEquals('12345', $array['id']);
        // Verify nested Data objects are handled (assuming Data has toArray method or is cast)
        // If Data extends stdClass or has toArray it works

        $sqlData = $event->toSqlData();
        $this->assertIsArray($sqlData);
        $this->assertIsString($sqlData['payload']); // sql data json_encodes objects/arrays
        $this->assertEquals('{"key":"value"}', $sqlData['payload']);

        $json = (string) $event;
        $this->assertJson($json);
        $decoded = json_decode($json, true);
        $this->assertEquals('test.event', $decoded['name']);
    }
}