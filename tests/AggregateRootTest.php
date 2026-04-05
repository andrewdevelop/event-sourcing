<?php

namespace Tests;

use Core\EventSourcing\AggregateRoot;
use Core\EventSourcing\DomainEvent;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class TestEvent extends DomainEvent
{
    public function __construct(mixed $data = [])
    {
        $data['name'] = $data['name'] ?? 'test.event';
        parent::__construct($data);
    }
}

class MissingHandlerEvent extends DomainEvent
{
    public function __construct(mixed $data = [])
    {
        $data['name'] = $data['name'] ?? 'missing.handler';
        parent::__construct($data);
    }
}

class TestAggregate extends AggregateRoot
{
    protected array $defaults = [
        'status' => 'new'
    ];

    public static $onCreatingCalled = false;

    public static function onCreating(TestAggregate $instance): void
    {
        self::$onCreatingCalled = true;
    }

    protected function applyTestEvent(TestEvent $event): void
    {
        $this->setAttribute('status', 'active');
        $this->setAttribute('event_applied', true);
        $this->setAttribute('applied_payload', $event->getPayload()->value ?? null);
    }
}

class AggregateRootTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TestAggregate::$onCreatingCalled = false;
    }

    public function test_it_creates_new_instance_with_generated_uuid()
    {
        $aggregate = TestAggregate::newInstance();

        $this->assertNotEmpty($aggregate->getId());
        $this->assertTrue(TestAggregate::$onCreatingCalled);
        $this->assertEquals('new', $aggregate->getAttribute('status')); // Default attribute test
    }

    public function test_it_creates_new_instance_with_provided_uuid()
    {
        $aggregate = TestAggregate::newInstance('custom-uuid-123');

        $this->assertEquals('custom-uuid-123', $aggregate->getId());
    }

    public function test_it_records_event_and_applies_state_changes()
    {
        $aggregate = TestAggregate::newInstance('agg-1');
        $event = new TestEvent();
        $event->setPayload(['value' => 'success']);

        $aggregate->recordThat($event);

        $recordedEvents = $aggregate->getRecordedEvents();
        
        $this->assertCount(1, $recordedEvents);
        $this->assertSame($event, $recordedEvents[0]);
        
        // Verify event metadata updated
        $this->assertEquals('agg-1', $event->getAggregateId());
        $this->assertEquals(TestAggregate::class, $event->getAggregateType());
        $this->assertEquals(1, $event->getAggregateVersion());

        // Verify aggregate state
        $this->assertEquals(1, $aggregate->getVersion());
        $this->assertEquals('active', $aggregate->getAttribute('status'));
        $this->assertTrue($aggregate->getAttribute('event_applied'));
        $this->assertEquals('success', $aggregate->getAttribute('applied_payload'));
    }

    public function test_apply_throws_exception_if_handler_missing()
    {
        $aggregate = TestAggregate::newInstance();
        $event = new MissingHandlerEvent();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Handler method "Tests\TestAggregate::applyMissingHandler" not found.');

        $aggregate->recordThat($event);
    }

    public function test_it_reconstitutes_from_history()
    {
        $event1 = new TestEvent();
        $event1->setAggregateId('agg-2');
        $event1->setAggregateVersion(1);

        $event2 = new TestEvent();
        $event2->setAggregateId('agg-2');
        $event2->setAggregateVersion(2);
        $event2->setPayload(['value' => 'final']);

        $history = [$event1, $event2];

        $aggregate = TestAggregate::reconstituteFromHistory($history);

        $this->assertEquals('agg-2', $aggregate->getId());
        $this->assertEquals(2, $aggregate->getVersion());
        $this->assertEquals('final', $aggregate->getAttribute('applied_payload'));
    }

    public function test_it_reconstitutes_from_history_up_to_specific_version()
    {
        $event1 = new TestEvent();
        $event1->setAggregateId('agg-3');
        $event1->setAggregateVersion(1);
        $event1->setPayload(['value' => 'first']);

        $event2 = new TestEvent();
        $event2->setAggregateId('agg-3');
        $event2->setAggregateVersion(2);
        $event2->setPayload(['value' => 'second']);

        $history = [$event1, $event2];

        // Replay only up to version 1
        $aggregate = TestAggregate::reconstituteFromHistory($history, 1);

        $this->assertEquals(1, $aggregate->getVersion());
        $this->assertEquals('first', $aggregate->getAttribute('applied_payload'));
    }

    public function test_attributes_management_and_dirty_tracking()
    {
        $aggregate = TestAggregate::newInstance();
        
        $aggregate->setAttribute('foo', 'bar');
        $this->assertEquals('bar', $aggregate->getAttribute('foo'));
        $this->assertEquals('bar', $aggregate->foo); // Magic getter

        $aggregate->baz = 'qux'; // Magic setter
        $this->assertEquals('qux', $aggregate->getAttribute('baz'));

        $this->assertTrue($aggregate->hasAttribute('foo'));
        $this->assertFalse($aggregate->hasAttribute('non_existent'));

        $aggregate->fill(['a' => 1, 'b' => 2]);
        $this->assertEquals(1, $aggregate->getAttribute('a'));
        $this->assertEquals(2, $aggregate->getAttribute('b'));

        $filtered = $aggregate->getAttributes(['foo', 'a']);
        $this->assertArrayHasKey('foo', $filtered);
        $this->assertArrayHasKey('a', $filtered);
        $this->assertArrayNotHasKey('baz', $filtered);

        // Track changes (only tracked if aggregate_exists is true, which happens after reconstitution)
        // Let's mock a reconstituted aggregate to test dirty tracking
        $trackEvent = new TestEvent();
        $trackEvent->setAggregateId('test-track');
        $trackEvent->setAggregateVersion(1);

        $reconstituted = TestAggregate::reconstituteFromHistory([$trackEvent]);

        $reconstituted->setAttribute('tracked_attr', 'initial');
        $this->assertTrue($reconstituted->hasChanged('tracked_attr')); // Old value was null, new is 'initial'

        $reconstituted->setAttribute('tracked_attr', 'changed');
        $this->assertTrue($reconstituted->hasChanged('tracked_attr'));
    }

    public function test_serialization()
    {
        $aggregate = TestAggregate::newInstance('agg-serialize');
        $aggregate->setAttribute('key', 'value');

        $array = $aggregate->toArray();
        
        $this->assertIsArray($array);
        $this->assertEquals('agg-serialize', $array['uuid']);
        $this->assertEquals('value', $array['key']);
        $this->assertEquals(0, $array['version']);

        $json = json_encode($aggregate);
        $decoded = json_decode($json, true);
        
        $this->assertEquals('agg-serialize', $decoded['uuid']);
        $this->assertEquals('value', $decoded['key']);
    }
}