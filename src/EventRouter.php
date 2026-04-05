<?php

namespace Core\EventSourcing;

class EventRouter
{
	public function match($path, $event_name): bool
	{
		// split "user.created.wtf" to namespace "user" and event id "created.wtf".
		$segments = explode('.', $path, 2);

		// The first part can be the namespace or a wildcard.
		$namespace = $segments[0] ?? '*';

		// The event id can be empty, in this case it will be converted to a wildcard.
		$event_id = $segments[1] ?? '*';

		// Subscribe to any event.
		if ($namespace == '*' && $event_id == '*') {
			return true;
		} 

		// Subscribe to any namespace except the following.
		if ((str_contains($namespace, '!')) && $event_id == '*') {
			$regex = '/^'.str_replace('!', '', $namespace).'\..+/i';
			return !preg_match("/^$namespace\..+/i", $event_name);
		} 

		// Exact match (case-insensitive).
		if ($namespace != '*' && $event_id != '*') {
			return $path == $event_name;
		}

		// Matches namespace and any id.
		if ($namespace != '*' && $event_id == '*') {
			$regex = '/^'.$namespace.'\..+/i';
			return preg_match($regex, $event_name);
		}

		return false;
	}
}