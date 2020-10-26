<?php 

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Core\EventSourcing\Contracts\Repository;
use Core\EventSourcing\Contracts\AggregateRoot;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\EventStore;
use Exception;
use Psr\EventDispatcher\EventDispatcherInterface;

class EventSourcedRepository implements Repository
{

	/**
	 * Event store adapter instance.
	 * @var EventStore
	 */
	protected $store;
	
	/**
	 * A PSR-14 compatible Event Dispatcher.
	 * @var EventDispatcherInterface
	 */
	protected $dispatcher;

	/**
	 * Create instance.
	 * @param EventDispatcher  $dispatcher
	 * @param EventStore       $store
	 */
	public function __construct(EventDispatcher $dispatcher, EventStore $store = null)
	{
		$this->dispatcher = $dispatcher;
		$this->store = $store;
	}

    /**
     * Set event store adapter.
     * @param EventStore $store
     * @return EventSourcedRepository
     */
	public function setEventStore(EventStore $store = null)
	{
		$this->store = $store;
		return $this;
	}

    /**
     * Set event dispatcher adapter.
     * @param EventDispatcher $dispatcher
     * @return EventSourcedRepository
     */
	public function setDispatcher(EventDispatcher $dispatcher)
	{
		$this->dispatcher = $dispatcher;
		return $this;
	}

	/**
	 * Create a new instance of an aggregate root.
	 * @param  string $aggregate_root_class 
	 * @param  string $uuid Aggregate root identifier.
	 * @return AggregateRoot
	 */
	public function init($aggregate_root_class, $uuid = null)
	{
        return call_user_func([$aggregate_root_class, 'newInstance'], $uuid);
	}

    /**
     * Load aggregate root.
     * @param string $uuid Aggregate root identifier.
     * @param null|int $version
     * @return AggregateRoot
     * @throws Exception
     */
	public function load($uuid, $version = null)
	{
		if ($this->store) {
			$aggregate_root = $this->loadFromEventStore($uuid, $version);
		} else {
			throw new Exception('@todo: Reconstitution without event store not implemented yet.');
		}
		return $aggregate_root; 
	}

    /**
     * Alias for load method.
     * @param string $aggregate_uuid
     * @param null|int $version
     * @return AggregateRoot
     * @throws Exception
     */
	public function find($aggregate_uuid, $version = null)
	{
		return $this->load($aggregate_uuid, $version);
	}

	/**
	 * Fire all events and commit to the Event store
	 * @param  AggregateRoot $aggregate_root 
	 * @return AggregateRoot|null
	 */
	public function save(AggregateRoot $aggregate_root)
	{
		$recorded_events = $aggregate_root->getRecordedEvents();

		$commited 	= $this->commitEvents($recorded_events);
		
		$dispatched = $this->dispatchEvents($recorded_events);

		if ($commited && $dispatched) {
			return $aggregate_root;
		}
	}

	protected function loadFromEventStore($uuid, $version = null)
	{
		$recorded_events = $this->store->load($uuid);
		// Automatically resolve Aggregate root class.
		$aggregate_root_class = '\\'.data_get($recorded_events, '0.aggregate_type');

		$mapped_events = array_map(function($data) {
			return new DomainEvent((array) $data);
		}, $recorded_events);

        return call_user_func([$aggregate_root_class, 'reconstituteFromHistory'], $mapped_events, $version);
	}

	/**
	 * @todo Need some error handling.
	 * @param  array $recorded_events 
	 * @return boolean
	 */
	protected function dispatchEvents($recorded_events = [])
	{
		foreach ($recorded_events as $event) {
			$this->dispatcher->dispatch($event);
		}
		return true;
	}

	/**
	 * @todo Need some error handling.
	 * @param  array $recorded_events 
	 * @return boolean
	 */
	protected function commitEvents($recorded_events = [])
	{
		if ($this->store) {
			$recorded_events = array_filter($recorded_events, function($event) {
				if ($event instanceof Event) return $event->storable == true;
				return true;
			});

			$recorded_events = array_map(function($event) {
				if ($event instanceof Event) return $event->toSqlData();
				return $event;
			}, $recorded_events);
			return $this->store->commit($recorded_events);
		}
		return true;
	}
}