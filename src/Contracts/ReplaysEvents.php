<?php 

namespace Core\EventSourcing\Contracts;

use Core\Contracts\Event;

interface ReplaysEvents
{
	/** Used for indicate that the listener/subscriber can be run on replay */
}