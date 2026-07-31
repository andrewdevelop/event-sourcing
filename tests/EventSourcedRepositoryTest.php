<?php

namespace Tests;

use Core\EventSourcing\AggregateRoot;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\Contracts\Transactional;
use Core\EventSourcing\DomainEvent;
use Core\EventSourcing\EventSourcedRepository;
use PHPUnit\Framework\TestCase;

interface TransactionalEventStore extends EventStore, Transactional
{
}

class DummyAggregate extends AggregateRoot
{
    public static function onCreating(DummyAggregate $instance): void
    {
    }

    protected function applyDummyEvent(DomainEvent $event): void
    {
    }
}

class EventSourcedRepositoryTest extends TestCase
{
    public function test_it_saves_successfully_with_transactions()
    {
        $store = $this->createMock(TransactionalEventStore::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store);

        $aggregate = DummyAggregate::newInstance('agg-1');
        $event = new DomainEvent(['name' => 'dummy.event']);
        $aggregate->recordThat($event);

        $store->expects($this->once())->method('beginTransaction');
        $store->expects($this->once())->method('commit')->willReturn(true);
        $dispatcher->expects($this->once())->method('dispatch');
        $store->expects($this->once())->method('commitTransaction');
        $store->expects($this->never())->method('rollbackTransaction');

        $result = $repository->save($aggregate);

        $this->assertSame($aggregate, $result);
    }

    public function test_it_rolls_back_if_event_store_commit_fails()
    {
        $store = $this->createMock(TransactionalEventStore::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store);

        $aggregate = DummyAggregate::newInstance('agg-2');
        $aggregate->recordThat(new DomainEvent(['name' => 'dummy.event']));

        $store->expects($this->once())->method('beginTransaction');
        $store->expects($this->once())->method('commit')->willReturn(false); // simulating failure
        $dispatcher->expects($this->never())->method('dispatch'); // Should NOT be called
        $store->expects($this->never())->method('commitTransaction');
        $store->expects($this->once())->method('rollbackTransaction');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Failed to commit events to the EventStore.');

        $repository->save($aggregate);
    }

    public function test_it_rolls_back_if_dispatcher_fails()
    {
        $store = $this->createMock(TransactionalEventStore::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store);

        $aggregate = DummyAggregate::newInstance('agg-3');
        $aggregate->recordThat(new DomainEvent(['name' => 'dummy.event']));

        $store->expects($this->once())->method('beginTransaction');
        $store->expects($this->once())->method('commit')->willReturn(true);
        
        // Simulating dispatcher throwing an exception
        $dispatcher->expects($this->once())->method('dispatch')->willThrowException(new \RuntimeException('Listener failed!'));
        
        $store->expects($this->never())->method('commitTransaction');
        $store->expects($this->once())->method('rollbackTransaction');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Listener failed!');

        $repository->save($aggregate);
    }

    public function test_save_clears_recorded_events_so_a_later_save_does_not_recommit_them()
    {
        $store = $this->createMock(TransactionalEventStore::class);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $committedBatches = [];
        $store->method('commit')->willReturnCallback(function ($events) use (&$committedBatches) {
            $committedBatches[] = $events;
            return true;
        });

        $repository = new EventSourcedRepository($dispatcher, $store);

        $aggregate = DummyAggregate::newInstance('agg-recommit');
        $aggregate->recordThat(new DomainEvent(['name' => 'dummy.event']));
        $repository->save($aggregate);

        $this->assertEmpty($aggregate->getRecordedEvents());

        // Recording and saving one more event on the SAME instance must only commit
        // that new event - not the one already committed above.
        $aggregate->recordThat(new DomainEvent(['name' => 'dummy.event']));
        $repository->save($aggregate);

        $this->assertCount(2, $committedBatches);
        $this->assertCount(1, $committedBatches[0]);
        $this->assertCount(1, $committedBatches[1]);
    }
}