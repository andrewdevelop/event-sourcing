<?php 

namespace Core\EventSourcing;

use Core\EventSourcing\Contracts\ReplaysEvents;

/** 
 * Same as reactor except the ability to replay events from the store.
 * Attention: don't place here handlers with side effects!
 */
abstract class Projector extends Reactor implements ReplaysEvents
{
	// ...
} 