<?php 

namespace Core\EventSourcing\Contracts;

use Core\EventSourcing\Contracts\RecordsEvents; 
use Core\EventSourcing\Contracts\AppliesRecordedEvents;

interface AggregateRoot extends RecordsEvents, AppliesRecordedEvents
{
	/**
	 * May return a version 4 UUID.
	 * @return string
	 */
	public function getId();
}