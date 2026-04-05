<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface RecordsEvents
{
	/**
	 * Get recorded events.
	 * @return array
	 */
	public function getRecordedEvents(): iterable;

    /**
     * Record a new event.
     * @param Event $event
     * @return void
     */
	public function recordThat(Event $event): void;
}