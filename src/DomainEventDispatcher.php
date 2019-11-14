<?php

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Core\EventSourcing\Contracts\EventDispatcher;
use Illuminate\Contracts\Container\Container;
use Exception;

/*
 * A very simple domain event dispatcher
 */
class DomainEventDispatcher implements EventDispatcher
{
    /**
     * The IoC container instance.
     * @var \Illuminate\Contracts\Container\Container
     */
    protected $container;

    /**
     * The registered event listeners.
     * @var array
     */    	
    protected $listeners = [];


    /**
     * Create a new event dispatcher instance.
     * @param \Illuminate\Contracts\Container\Container
     */
    public function __construct(Container $container)
    {
        $this->container = $container;
    }


    /**
     * Register an event listener with the dispatcher.
     * @param  $events
     * @param  string  $listener
     * @return self
     */
    public function listen($listener)
    {
    	if (is_array($listener)) {
    		$listener = array_map(function($class) {
    			return $this->makeListener($class);
    		}, $listener);
    		$this->listeners = array_merge($this->listeners, $listener);
    	} else {
    		$this->listeners[] = $this->makeListener($listener);
    	}
        return $this;
    }


    /**
     * Dispatch a single event.
     * @param object  $event
     */
    public function dispatch(Event $event)
    {
        if (method_exists($event, 'getName')) {
        	$event_name = $event->getName();
        } elseif (defined(get_class($event).'::NAME')) {
        	$event_name = $event::NAME;
        } else {
        	throw new Exception('Event name not defined.');
        }

        $this->sendToListeners($event_name, $event);

        return $event;
    }


    public function sendToListeners($event_name, $event)
    {
    	foreach ($this->listeners as $listener) {
    		$listener($event_name, $event);
    	}
    }

    /**
     * Register an event listener with the dispatcher.
     * @param  string  $listener
     * @return \Closure
     */
	public function makeListener($listener)
    {
        if (is_callable($listener)) {
            return $this->createClosureListener($listener);
        } else {
            return $this->createCallableListener($listener);
        }
    }


    /**
     * Create a class based listener using the IoC container.
     * @param  string  $listener
     * @return \Closure
     */
    public function createCallableListener($listener)
    {
        return function ($event_name, $event) use ($listener) {
            return call_user_func([$this->container->make($listener), 'handle'], $event_name, $event);
        };
    }

    public function createClosureListener(\Closure $listener)
    {
        return function ($event_name, $event) use ($listener) {
            return call_user_func($listener, $event_name, $event);
        };
    }

}
