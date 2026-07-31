<?php

namespace Core\EventSourcing;

use Closure;
use Core\Contracts\Event;
use Core\EventSourcing\Contracts\AggregateRoot;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\EventStore;
use Core\EventSourcing\Contracts\Repository;
use Core\EventSourcing\Contracts\RequestsErasure;
use Core\EventSourcing\Contracts\Transactional;
use Core\EventSourcing\Pii\PiiAnonymizer;
use Illuminate\Support\LazyCollection;
use RuntimeException;
use Throwable;

class EventSourcedRepository implements Repository
{
    protected Closure|null $on_begin = null;
    protected Closure|null $on_commit = null;
    protected Closure|null $on_rollback = null;

    public function __construct(
        protected EventDispatcher $dispatcher,
        protected EventStore|null $store = null,
        protected PiiAnonymizer|null $anonymizer = null,
    )
    {
    }

    /**
     * Set event store adapter.
     * @param EventStore|null $store
     * @return EventSourcedRepository
     */
    public function setEventStore(EventStore $store = null): static
    {
        $this->store = $store;
        return $this;
    }

    /**
     * Set event dispatcher adapter.
     * @param EventDispatcher $dispatcher
     * @return EventSourcedRepository
     */
    public function setDispatcher(EventDispatcher $dispatcher): static
    {
        $this->dispatcher = $dispatcher;
        return $this;
    }

    /**
     * Create a new instance of an aggregate root.
     * @param string $aggregate_root_class
     * @param string|null $uuid Aggregate root identifier.
     * @return AggregateRoot
     */
    public function init(string $aggregate_root_class, ?string $uuid = null): AggregateRoot
    {
        return call_user_func([$aggregate_root_class, 'newInstance'], $uuid);
    }

    /**
     * Alias for load method.
     * @param string $aggregate_uuid
     * @param null|int $version
     * @return AggregateRoot
     * @throws Throwable
     */
    public function find(string $aggregate_uuid, ?int $version = null): AggregateRoot
    {
        return $this->load($aggregate_uuid, $version);
    }

    /**
     * Load aggregate root.
     * @param string $uuid Aggregate root identifier.
     * @param null|int $version
     * @return AggregateRoot
     * @throws RuntimeException
     */
    public function load(string $uuid, ?int $version = null): AggregateRoot
    {
        if ($this->store) {
            $aggregate_root = $this->loadFromEventStore($uuid, $version);
        } else {
            throw new RuntimeException('@todo: Reconstitution without event store not implemented yet.');
        }
        return $aggregate_root;
    }

    protected function loadFromEventStore(string $uuid, ?int $version = null)
    {
        /** @var LazyCollection $recorded_events */
        $recorded_events = $this->store->load($uuid);

        // Automatically resolve Aggregate root class.
        $aggregate_root_class = '\\' . data_get($recorded_events->first(), 'aggregate_type');

        $recorded_events = $recorded_events->map(function ($data) {
            $event = new DomainEvent((array)$data);
            // Decrypt PII for subjects who haven't been forgotten, so the aggregate's
            // business logic sees real data; no-op otherwise. See GDPR_CCPA.md.
            $this->anonymizer?->open($event);
            return $event;
        });

        return call_user_func([$aggregate_root_class, 'reconstituteFromHistory'], $recorded_events, $version);
    }

    /**
     * Fire all events and commit to the Event store
     * @param AggregateRoot $aggregate
     * @return AggregateRoot
     * @throws Throwable
     */
    public function save(AggregateRoot $aggregate): AggregateRoot
    {
        $recorded_events = $aggregate->getRecordedEvents();

        if (empty($recorded_events)) {
            return $aggregate;
        }

        if ($this->store instanceof Transactional) $this->store->beginTransaction();
        if (is_callable($this->on_begin)) $this->on_begin->__invoke($recorded_events);

        try {
            $ok = $this->commitEvents($recorded_events);
            if (!$ok) {
                throw new RuntimeException('Failed to commit events to the EventStore.');
            }

            $ok = $this->dispatchEvents($recorded_events);
            if (!$ok) {
                throw new RuntimeException('Failed to dispatch events.');
            }

            if ($this->store instanceof Transactional) $this->store->commitTransaction();

            // Only crypto-shred once the write is durably committed: forgetting a
            // subject is irreversible and must never happen for a write that could
            // still be rolled back. See GDPR_CCPA.md.
            $this->forgetErasedSubjects($recorded_events);

            if (is_callable($this->on_commit)) $this->on_commit->__invoke($recorded_events);

            // These events are now durably committed: clear the buffer so a later
            // save() call on this same instance doesn't recommit, re-dispatch, or
            // re-forget them.
            $aggregate->clearRecordedEvents();

            return $aggregate;

        } catch (Throwable $e) {
            if ($this->store instanceof Transactional) $this->store->rollbackTransaction();
            if (is_callable($this->on_rollback)) $this->on_rollback->__invoke($recorded_events, $e);
            throw $e;
        }
    }

    /**
     * Execute a GDPR/CCPA erasure request directly: irreversibly destroy a data
     * subject's encryption key via crypto-shredding. Useful for triggering "forget me"
     * from application code (e.g. a command handler) rather than only from the
     * es:forget console command. See GDPR_CCPA.md.
     * @param string $pii_id
     * @return void
     */
    public function forgetDataSubject(string $pii_id): void
    {
        if (!$this->anonymizer) {
            throw new RuntimeException('Cannot forget a data subject: no PiiAnonymizer configured.');
        }
        $this->anonymizer->forget($pii_id);
    }

    /**
     * Crypto-shred every subject whose just-committed event asked to be forgotten
     * (Contracts\RequestsErasure) - lets an aggregate model "delete me" as an ordinary
     * domain event recorded through recordThat()/save(), the same mechanism used for
     * any other event, instead of requiring a separate call.
     * @param array $recorded_events
     * @return void
     */
    protected function forgetErasedSubjects(array $recorded_events = []): void
    {
        if (!$this->anonymizer) return;

        foreach ($recorded_events as $event) {
            if (!$event instanceof RequestsErasure) continue;
            if (!$event instanceof DomainEvent) continue;

            $pii_id = $event->getPiiId();
            if ($pii_id) $this->anonymizer->forget($pii_id);
        }
    }

    /**
     * @param array $recorded_events
     * @return boolean
     */
    protected function commitEvents(array $recorded_events = []): bool
    {
        if ($this->store) {
            $recorded_events = array_map(function ($event) {
                if ($event instanceof Event) {
                    // Seal a clone for storage: the original keeps its plaintext for
                    // dispatchEvents(), which runs right after and may drive real-time
                    // side effects (e.g. sending a confirmation email). See GDPR_CCPA.md.
                    if ($this->anonymizer && $event instanceof DomainEvent) {
                        $event = $this->anonymizer->seal(clone $event);
                    }
                    return $event->toSqlData();
                }
                return $event;
            }, $recorded_events);
            return $this->store->commit($recorded_events);
        }
        return true;
    }

    /**
     * @param array $recorded_events
     * @return boolean
     */
    protected function dispatchEvents(array $recorded_events = []): bool
    {
        foreach ($recorded_events as $event) {
            $this->dispatcher->dispatch($event);
        }
        return true;
    }

    public function onBeginTx(Closure|null $callback = null): void
    {
        if (!is_callable($callback)) throw new \InvalidArgumentException('Callback must be a valid Closure.');
        $this->on_begin = $callback;
    }

    public function onCommitTx(Closure|null $callback = null): void
    {
        if (!is_callable($callback)) throw new \InvalidArgumentException('Callback must be a valid Closure.');
        $this->on_commit = $callback;
    }

    public function onRollbackTx(Closure|null $callback = null): void
    {
        if (!is_callable($callback)) throw new \InvalidArgumentException('Callback must be a valid Closure.');
        $this->on_rollback = $callback;
    }
}
