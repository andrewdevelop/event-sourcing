<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface HandlesEvent
{
    /**
     * Apply an event to projection.
     * @param $event_name
     * @param Event $event
     * @return void
     */
	public function handle($event_name, Event $event);

}