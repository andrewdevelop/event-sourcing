<?php

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Core\EventSourcing\Contracts\HandlesEvent;

abstract class Reactor implements HandlesEvent
{
    /**
     * Make aggregate root capable to handling events and proxy state changes into handler method.
     * @param $event_name
     * @param Event $event
     * @return mixed
     */
    public function handle($event_name, Event $event)
    {
        $method = $this->getApplicableMethod($event_name);
        if (method_exists($this, $method)) {
            return call_user_func([$this, $method], $event);
        }
    }

    /**
     * Get the method name used for applying the event.
     * For example: "user.registered" mapped to "applyUserRegistered"
     * @param $event_name
     * @return string
     */
    protected function getApplicableMethod($event_name): string
    {
        $delimiters = [".", "-", "_"];
        return 'apply' . str_replace($delimiters, '', ucwords($event_name, implode('', $delimiters)));
    }
} 