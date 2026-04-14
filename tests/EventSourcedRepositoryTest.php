<?php

namespace Tests;

use Core\EventSourcing\AggregateRoot;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\DomainEvent;
use Core\EventSourcing\EventSourcedRepository;
use PHPUnit\Framework\TestCase;

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
        $store = $this->createMock(EventStore::class);
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
        $store = $this->createMock(EventStore::class);
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
        $store = $this->createMock(EventStore::class);
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
}