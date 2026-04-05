<?php

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Core\EventSourcing\Contracts\AggregateRoot as Contract;
use DateTimeInterface;
use JsonSerializable;
use Ramsey\Uuid\Uuid;
use RuntimeException;


abstract class AggregateRoot implements Contract, JsonSerializable
{
    /**
     * Unique identifier.
     * @var string
     */
    protected string $uuid;

    /**
     * Current version.
     * @var int
     */
    protected int $version = 0;

    /**
     * Version loaded form event store.
     * @var integer
     */
    protected int $last_version = 0;

    /**
     * Indicates if the aggregate exists.
     * @var bool
     */
    protected bool $aggregate_exists = false;

    /**
     * Determine if aggregate root reconstituted from history.
     * @var boolean
     */
    protected bool $is_reconstituted = false;

    /**
     * The AR's attributes.
     */
    protected Data|null $attributes = null;

    /**
     * The changed aggregate root attributes.
     * @var array
     */
    protected array $dirty = [];

    /**
     * Events that are not committed to the Event Store.
     * @var array
     */
    protected array $recorded_events = [];

    protected array $defaults = [];


    /**
     * We do not allow public access,
     * this way we make sure that an aggregate root can only be constructed
     * by static factory
     */
    protected function __construct()
    {
        $this->attributes = new Data();
        $this->initDefaults();
    }

    protected function initDefaults()
    {
        if (count($this->defaults)) {
            foreach ($this->defaults as $key => $value) {
                $this->setAttribute($key, $value);
            }
        }
    }

    /**
     * Create new instance.
     * @param string|null $uuid [uuid added for client generated uuid]
     * @return static
     */
    public static function newInstance(?string $uuid = null): static
    {
        $instance = new static();
        if ($uuid) {
            $instance->uuid = $uuid;
        } else {
            $instance->uuid = Uuid::uuid4()->toString();
        }

        // Make a hook (internal event)
        if (method_exists(get_called_class(), 'onCreating')) {
            call_user_func([get_called_class(), 'onCreating'], $instance);
        }

        return $instance;
    }

    /**
     * Get uuid.
     * @return string
     */
    public function getId(): string
    {
        return $this->uuid;
    }

    /**
     * Set uuid
     * @param string $uuid
     */
    public function setId(string $uuid): void
    {
        $this->uuid = $uuid;
    }


    /**
     * Get current version of instance.
     * @return string
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * Get recorded events.
     * @return array
     */
    public function getRecordedEvents(): iterable
    {
        return $this->recorded_events;
    }


    /**
     * Record a new event.
     * @param Event $event
     * @return void
     * @version 1.1 $event->aggregate_type added for bulk replay/projection
     */
    public function recordThat(Event $event): void
    {
        $this->version += 1;
        $event->aggregate_id = $this->getId();
        $event->aggregate_version = $this->version;
        $event->aggregate_type = get_class($this);

        $this->apply($event);

        $this->recorded_events[] = $event;
    }


    /**
     * Reconstitute current state or given version of aggregate root.
     * @param array $history_events
     * @param int|null $version
     */
    public static function reconstituteFromHistory($history_events, $version = null): static
    {
        $instance = self::instantiateForReconstruction();
        $instance->applyRecordedEvents($history_events, $version);

        $instance->aggregate_exists = true;
        $instance->is_reconstituted = true;

        return $instance;
    }


    /**
     * Create a new instance.
     * @return static
     */
    public static function instantiateForReconstruction(): static
    {
        return new static();
    }


    /**
     * Initializing state (rebuild) from previously recorded events.
     * @param iterable $recorded_events
     * @param int|null $version
     * @return void
     */
    public function applyRecordedEvents(iterable $recorded_events, ?int $version = null): void
    {
        foreach ($recorded_events as $event) {

            // Important! set aggregate id
            if (!isset($this->uuid)) $this->setId($event->aggregate_id);

            // If we need a concrete version (snapshot) of the Aggregate root
            // we just break the loop at the given version.
            if ($version !== null && $event->aggregate_version > $version) {
                break;
            }

            $this->version = $event->aggregate_version;
            $this->apply($event);
        }

        $this->last_version = $this->version;
    }

    /**
     * Make aggregate root capable to handling events and proxy state changes into handler method.
     * @param Event $event
     * @return void
     */
    public function apply(Event $event): void
    {
        $method = $this->getApplicableMethod($event);
        if (method_exists($this, $method)) {
            call_user_func_array([$this, $method], [$event]);
        } else {
            throw new RuntimeException("Handler method \"" . get_called_class() . "::{$method}\" not found.");
        }
    }

    /**
     * Get the method name used for applying the recorded event.
     * For example: "user.registered" mapped to "applyUserRegistered"
     * @param Event $event
     * @return string
     */
    protected function getApplicableMethod(Event $event): string
    {
        $delimiters = [".", "-", "_"];
        return 'apply' . str_replace($delimiters, '', ucwords($event->name, implode('', $delimiters)));
    }


    /**
     * Fill the aggregate root attribute DTO with an array of attributes.
     * @param iterable $attributes
     * @return void
     */
    public function fill(iterable $attributes): void
    {
        foreach ($attributes as $key => $value) {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Set a given attribute on the attribute DTO.
     * @param string $key
     * @param mixed $value
     * @return static
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->trackAttributeChanges($key, $value);

        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Get an attribute from the attributes array.
     * @param string $key
     * @return mixed
     */
    public function getAttribute(string $key): mixed
    {
        if (!$key) return null;
        if ($this->hasAttribute($key)) {
            return $this->attributes[$key];
        }
        return null;
    }

    /**
     * If the attribute exists.
     * @param string $key
     * @return boolean
     */
    public function hasAttribute(string $key): bool
    {
        return $this->attributes->offsetExists($key);
    }

    /**
     * Get all attributes.
     * @param null $filter
     * @return array
     */
    public function getAttributes($filter = null): array
    {
        $mapped = [];
        foreach ($this->attributes as $key => $v) {
            if (is_array($filter) && count($filter) > 0) {
                if (in_array($key, $filter)) {
                    $mapped[$key] = $this->getAttribute($key);
                }
            } else {
                $mapped[$key] = $this->getAttribute($key);
            }
        }
        return $mapped;
    }

    /**
     * Determine if the given attribute have been modified.
     * @param string $key
     * @return bool
     */
    public function hasChanged(string $key): bool
    {
        return array_key_exists($key, $this->dirty);
    }

    /**
     * Sync the changed attributes.
     * @param string $key
     * @param mixed $new_value
     * @return void
     */
    protected function trackAttributeChanges(string $key, mixed $new_value): void
    {
        if ($this->aggregate_exists) {
            $old_value = $this->getAttribute($key);
            if (!$this->isValuesEquivalent($old_value, $new_value)) {
                $this->dirty[$key] = [$old_value, $new_value];
            }
        }
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     * @param mixed $old_value
     * @param mixed $new_value
     * @return bool
     */
    public function isValuesEquivalent(mixed $old_value, mixed $new_value): bool
    {
        if ($new_value === $old_value) {
            return true;
        } elseif (is_null($new_value)) {
            return false;
        }
        // elseif {} ... arrays or objects ?
        return is_numeric($new_value) && is_numeric($old_value) && strcmp((string)$new_value, (string)$old_value) === 0;
    }

    /**
     * Determine if the new and old values for a given key are equivalent.
     * @param string $key
     * @param mixed $value
     * @return boolean
     */
    public function isAttributeEquivalent(string $key, mixed $value): bool
    {
        $old_value = $this->getAttribute($key);
        return $this->isValuesEquivalent($old_value, $value);
    }

    /**
     * Dynamically retrieve attributes on the aggreagate root.
     * @param string $key
     * @return mixed
     */
    public function __get(string $key): mixed
    {
        if (property_exists($this, $key)) return $this->{$key};
        return $this->getAttribute($key);
    }


    /**
     * Dynamically set attributes on the aggreagate root.
     * @param string $key
     * @param mixed $value
     * @return void
     */
    public function __set(string $key, mixed $value): void
    {
        if (property_exists($this, $key)) {
            $this->{$key} = $value;
        } else {
            $this->setAttribute($key, $value);
        }
    }

    /**
     * Convert the instance to an array.
     * @return array
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;
        if (is_object($attributes)) {
            $attributes = call_user_func([$attributes, 'toArray']);
        }

        $attributes['version'] = $this->version;
        $attributes['uuid'] = $this->uuid;

        return array_map(function ($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s.u');
            } elseif ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            } elseif (is_object($value) && method_exists($value, 'value')) {
                return $value->value();
            } elseif (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            } elseif ($value instanceof \stdClass) {
                return (array)$value;
            }
            return $value;
        }, array_reverse($attributes));
    }


    /**
     * Convert the object into something JSON serializable.
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}