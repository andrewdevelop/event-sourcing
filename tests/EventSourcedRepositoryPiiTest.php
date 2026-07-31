<?php

namespace Tests;

use Core\EventSourcing\AggregateRoot;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\Contracts\KeyRing;
use Core\EventSourcing\Contracts\RequestsErasure;
use Core\EventSourcing\DomainEvent;
use Core\EventSourcing\EventSourcedRepository;
use Core\EventSourcing\Pii\InMemoryKeyRing;
use Core\EventSourcing\Pii\PiiAnonymizer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class UserErasureRequestedForTest extends DomainEvent implements RequestsErasure
{
    protected $name = 'user.erasure_requested';
}

class PiiDummyAggregate extends AggregateRoot
{
    public ?string $captured_email = null;

    public static function onCreating(PiiDummyAggregate $instance): void
    {
    }

    protected function applyUserRegistered(DomainEvent $event): void
    {
        $this->captured_email = $event->getPayload()->email;
    }

    protected function applyUserErasureRequested(DomainEvent $event): void
    {
    }
}

class EventSourcedRepositoryPiiTest extends TestCase
{
    public function test_commit_seals_pii_while_the_dispatched_event_stays_plaintext()
    {
        $store = $this->createMock(EventStore::class);
        $dispatcher = $this->createMock(EventDispatcher::class);
        $anonymizer = new PiiAnonymizer(new InMemoryKeyRing());

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        $aggregate = PiiDummyAggregate::newInstance('agg-1');
        $event = new UserRegisteredForTest(['payload' => ['email' => 'jane@example.com']]);
        $event->setPiiId('subject-1');
        $aggregate->recordThat($event);

        $committed = null;
        $store->method('commit')->willReturnCallback(function ($events) use (&$committed) {
            $committed = $events;
            return true;
        });

        $dispatched = null;
        $dispatcher->method('dispatch')->willReturnCallback(function ($event) use (&$dispatched) {
            $dispatched = $event;
        });

        $repository->save($aggregate);

        // What hits the store is ciphertext.
        $storedPayload = json_decode($committed[0]['payload'], true);
        $this->assertNotEquals('jane@example.com', $storedPayload['email']);

        // What gets dispatched in-process for real-time side effects stays plaintext -
        // sealing must not mutate the recorded event itself, only a clone of it.
        $this->assertEquals('jane@example.com', $dispatched->getPayload()->email);
    }

    public function test_load_decrypts_pii_for_a_subject_that_has_not_been_forgotten()
    {
        $keys = new InMemoryKeyRing();
        $anonymizer = new PiiAnonymizer($keys);

        $sealed = new UserRegisteredForTest(['payload' => ['email' => 'jane@example.com']]);
        $sealed->setPiiId('subject-1');
        $sealed->setAggregateId('agg-2');
        $sealed->setAggregateType(PiiDummyAggregate::class);
        $sealed->setAggregateVersion(1);
        $anonymizer->seal($sealed);

        $store = $this->createMock(EventStore::class);
        $store->method('load')->willReturn(collect([(object) $sealed->toArray()]));
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        $aggregate = $repository->load('agg-2');

        $this->assertInstanceOf(PiiDummyAggregate::class, $aggregate);
        $this->assertEquals('jane@example.com', $aggregate->captured_email);
    }

    public function test_forget_data_subject_delegates_to_the_anonymizer()
    {
        $keys = new InMemoryKeyRing();
        $keys->put('subject-1', 'some-key');
        $anonymizer = new PiiAnonymizer($keys);

        $repository = new EventSourcedRepository(
            $this->createMock(EventDispatcher::class),
            $this->createMock(EventStore::class),
            $anonymizer
        );

        $repository->forgetDataSubject('subject-1');

        $this->assertNull($keys->get('subject-1'));
    }

    public function test_forget_data_subject_without_an_anonymizer_throws()
    {
        $repository = new EventSourcedRepository($this->createMock(EventDispatcher::class));

        $this->expectException(RuntimeException::class);

        $repository->forgetDataSubject('subject-1');
    }

    public function test_save_automatically_forgets_subjects_of_committed_erasure_events()
    {
        $keys = new InMemoryKeyRing();
        $keys->put('subject-1', 'some-key');
        $anonymizer = new PiiAnonymizer($keys);

        $store = $this->createMock(EventStore::class);
        $store->method('commit')->willReturn(true);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        $aggregate = PiiDummyAggregate::newInstance('agg-3');
        $event = new UserErasureRequestedForTest();
        $event->setPiiId('subject-1');
        $aggregate->recordThat($event);

        $repository->save($aggregate);

        // Recording and saving an ordinary domain event was enough to forget the
        // subject - no separate call or CLI command needed.
        $this->assertNull($keys->get('subject-1'));
    }

    public function test_save_does_not_forget_the_subject_if_the_commit_fails()
    {
        $keys = new InMemoryKeyRing();
        $keys->put('subject-1', 'some-key');
        $anonymizer = new PiiAnonymizer($keys);

        $store = $this->createMock(EventStore::class);
        $store->method('commit')->willReturn(false); // simulate a failed write
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        $aggregate = PiiDummyAggregate::newInstance('agg-4');
        $event = new UserErasureRequestedForTest();
        $event->setPiiId('subject-1');
        $aggregate->recordThat($event);

        try {
            $repository->save($aggregate);
        } catch (RuntimeException $e) {
            // expected: commit failed
        }

        // The key must survive an uncommitted write: destroying it is irreversible.
        $this->assertEquals('some-key', $keys->get('subject-1'));
    }

    public function test_replaying_a_past_erasure_event_during_reconstitution_does_not_re_forget_it()
    {
        $keys = $this->createMock(KeyRing::class);
        // forget() must fire for the ORIGINAL commit below, and never again afterwards.
        $keys->expects($this->once())->method('forget')->with('subject-1');
        $anonymizer = new PiiAnonymizer($keys);

        $store = $this->createMock(EventStore::class);
        $store->method('commit')->willReturn(true);
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        // 1) The original erasure request gets committed and forgotten exactly once.
        $aggregate = PiiDummyAggregate::newInstance('agg-5');
        $erasure = new UserErasureRequestedForTest();
        $erasure->setPiiId('subject-1');
        $aggregate->recordThat($erasure);
        $repository->save($aggregate);

        // 2) Later, the aggregate is reconstituted from its full history - as
        // repository->load() (and equally, `es:replay`'s dispatcher->replay()) does -
        // replaying that same erasure event into applyUserErasureRequested().
        $store->method('load')->willReturn(collect([(object) $erasure->toArray()]));
        $reconstituted = $repository->load('agg-5');

        // 3) Recording and saving a brand-new, unrelated event must not re-forget
        // subject-1: applyRecordedEvents() (used by reconstitution) never re-adds
        // historical events to getRecordedEvents(), so they never re-enter the commit
        // pipeline that triggers forgetErasedSubjects().
        $reconstituted->recordThat(new UserRegisteredForTest(['payload' => ['email' => 'x@example.com']]));
        $repository->save($reconstituted);
    }

    public function test_aggregate_reconstitutes_after_forgetting_but_with_gibberish_instead_of_real_pii()
    {
        $keys = new InMemoryKeyRing();
        $anonymizer = new PiiAnonymizer($keys);

        $sealed = new UserRegisteredForTest(['payload' => ['email' => 'jane@example.com', 'full_name' => 'Jane Doe']]);
        $sealed->setPiiId('subject-1');
        $sealed->setAggregateId('agg-7');
        $sealed->setAggregateType(PiiDummyAggregate::class);
        $sealed->setAggregateVersion(1);
        $anonymizer->seal($sealed);

        $ciphertextEmail = $sealed->getPayload()->email;

        // The subject asks to be forgotten: destroy their key.
        $anonymizer->forget('subject-1');

        $store = $this->createMock(EventStore::class);
        $store->method('load')->willReturn(collect([(object) $sealed->toArray()]));
        $dispatcher = $this->createMock(EventDispatcher::class);

        $repository = new EventSourcedRepository($dispatcher, $store, $anonymizer);

        // Reconstitution must still succeed - the event log itself is untouched by
        // erasure and stays fully loadable/replayable.
        $aggregate = $repository->load('agg-7');
        $this->assertInstanceOf(PiiDummyAggregate::class, $aggregate);

        // But what comes out is gibberish, not the real value and not an exception:
        // the aggregate's business logic just sees the ciphertext, unrecoverable.
        $this->assertNotEquals('jane@example.com', $aggregate->captured_email);
        $this->assertEquals($ciphertextEmail, $aggregate->captured_email);
    }
}
