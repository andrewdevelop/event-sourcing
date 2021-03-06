<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface AppliesRecordedEvents
{
	/**
	 * Initializing state from previously recorded events.
	 * @param  iterable  $recorded_events 
	 * @return void                  
	 */
	public function applyRecordedEvents(iterable $recorded_events);

	/**
	 * Apply an event to rebuild state.
	 * @param  Event  $event 
	 * @return void        
	 */
	public function apply(Event $event);

}