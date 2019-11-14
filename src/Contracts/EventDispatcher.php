<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface EventDispatcher
{
	/**
     * Provide all relevant listeners with an event to process.
 	 */
	public function dispatch(Event $event);
}