<?php 

namespace Core\EventSourcing\Contracts;

interface EventStore
{
	/**
	 * Store events
	 * @param  iterable  $events 
	 * @return boolean
	 */
	public function commit(iterable $events);

	/**
	 * Load events for aggregate
	 * @param  string $aggregate_id 
	 * @return array
	 */
	public function load($aggregate_id);

	/**
	 * Load all events.
	 * @return array
	 */
	public function loadAll();
}