<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface RecordsEvents
{
	/**
	 * Get recorded events.
	 * @return array
	 */
	public function getRecordedEvents();

	/**
	 * Record a new event.
	 * @return void
	 */
	public function recordThat(Event $event);
}