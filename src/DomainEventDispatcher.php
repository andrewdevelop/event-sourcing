<?php

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Core\EventSourcing\Contracts\EventDispatcher;
use Core\EventSourcing\Contracts\ReplaysEvents;
use Illuminate\Contracts\Container\Container;
use Exception;
use Closure;

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
        $this->router = new EventRouter();
    }


    /**
     * Register an event listener with the dispatcher.
     * @param  string        $routing_key
     * @param  string|array  $events
     * @param  string        $listener
     * @return self
     */
    public function listen($routing_key, $listener)
    {
    	if (is_array($listener)) {
    		foreach ($listener as $elem) {
                $this->listen($routing_key, $elem);
            }
    	} else {
    		$this->listeners[$routing_key][] = $this->makeListener($listener);
    	}
        return $this;
    }


    /**
     * Dispatch a single event.
     * @param  \Core\Contracts\Event  $event
     * @return \Core\Contracts\Event  $event
     */
    public function dispatch(Event $event)
    {
        $event_name = $this->getEventName($event);
        foreach ($this->listeners as $routing_key => $listeners) {

            // Filtering events.
            if (!$this->router->match($routing_key, $event_name)) continue;

            foreach ($listeners as $listener) {
                if ($listener instanceof Closure) {
                    call_user_func($listener, $event_name, $event);
                } else {
                    call_user_func([$listener, 'handle'], $event_name, $event);
                }
            }

        }
        return $event;
    }


    /**
     * Dispatch projectors only.
     * @param  \Core\Contracts\Event  $event
     * @return \Core\Contracts\Event  $event
     */
    public function replay(Event $event)
    {
        $event_name = $this->getEventName($event);
        foreach ($this->listeners as $listener) {
            if (is_subclass_of($listener, ReplaysEvents::class)) {
                call_user_func([$listener, 'handle'], $event_name, $event);
            }
        }        
        return $event;
    }


    /**
     * Get the event's name.
     * @param  \Core\Contracts\Event  $event
     * @return string
     */
    protected function getEventName(Event $event)
    {
        if (method_exists($event, 'getName')) {
            return $event->getName();
        } elseif (defined(get_class($event).'::NAME')) {
            return $event::NAME;
        } else {
            throw new Exception('Event name not defined.');
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
     * @return object
     */
    public function createCallableListener($listener)
    {
        return $this->container->make($listener);
    }


    /**
     * Create a listener as a lambda.
     * @param  Closure $listener 
     * @return Closure
     */
    public function createClosureListener(Closure $listener)
    {
        return $listener;
    }

}
