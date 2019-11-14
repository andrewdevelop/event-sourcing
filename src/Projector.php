<?php 

namespace Core\EventSourcing;

use Core\EventSourcing\Contracts\HandlesEvent;
use Core\Contracts\Event;
use Illuminate\Contracts\Container\Container;

abstract class Projector implements HandlesEvent
{
	/**
	 * Make aggreagate root capable to handling events and proxy state changes into handler method.
	 * @param  Event  $event 
	 * @return mixed
	 */
	public function handle($event_name, Event $event)
	{
		$method = $this->getApplyableMethod($event_name);
		if (method_exists($this, $method)) {
			return call_user_func([$this, $method], $event);
		}
	}

	/**
	 * Get the method name used for applying the event.
     * For example: user.registered mapped to applyUserRegistered
	 * @param  Event  $event 
	 * @return string
	 */
	protected function getApplyableMethod($event_name)
	{
        $delimiters = [".", "-", "_"];
        $method = 'apply' . str_replace($delimiters, '', ucwords($event_name, implode('', $delimiters)));
		return $method;
	}
} 