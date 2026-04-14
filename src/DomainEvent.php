<?php 

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Ramsey\Uuid\Uuid;
use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;

/**
 * Class DomainEvent
 * @package Core\EventSourcing
 * @property string $id
 * @property string $name
 * @property int $version
 * @property string $aggregate_id
 * @property string $aggregate_type
 * @property int $aggregate_version
 * @property object|array $payload
 * @property object|array $metadata
 * @property DateTimeInterface|string $created_at
 */
class DomainEvent implements Event
{
    /**
     * UUID 4
     * @var string
     */
    protected string $id;

    /**
     * Event name
     * @var string
     */
    protected $name;

    /**
     * Event versioning.
     * @var integer
     */
    protected int $version;

    /**
     * Aggregate UUID.
     * @var string
     */
    protected string $aggregate_id;

    /**
     * Class of aggregate root.
     * @var string
     */
    protected string $aggregate_type;

    /**
     * Aggregate version.
     * @var int
     */
    protected int $aggregate_version;

    /**
     * Event payload
     * @var Data|null
     */
    protected Data|null $payload = null;

    /**
     * Event meta
     * @var Data|null
     */
    protected Data|null $metadata = null;

    /**
     * Event created at
     * @var DateTimeInterface
     */
    protected DateTimeInterface $created_at;

    /**
     * Instantiate a new event.
     * @param array|object $data 
     */
    public function __construct(mixed $data = [])
    {
        $keys = array_keys(get_class_vars(static::class));
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            $this->setAttribute($key, $value);
        }
        if (empty($this->name)) {
            throw new InvalidArgumentException('Event name not defined.');
        }
    }

    /**
     * Magic getter.
     * @param  string $key 
     * @return mixed      
     */
    public function __get(string $key): mixed
    {
        if (property_exists($this, $key)) return $this->getAttribute($key);
        return null;
    }

    /**
     * Magic setter.
     * @param string $key
     * @param mixed $value 
     */
    public function __set(string $key, mixed $value): void
    {
        if (property_exists($this, $key)) $this->setAttribute($key, $value);
    }


    /**
     * Get attribute.
     * @param  string $key 
     * @return mixed
     */
    protected function getAttribute(string $key): mixed
    {
        $method = 'get' . str_replace('_', '', ucwords($key, '_'));
        if (method_exists($this, $method)) return call_user_func([$this, $method]);
        return $this->{$key};
    }

    /**
     * Set a given attribute.
     * @param  string  $key
     * @param  mixed  $value
     */
    protected function setAttribute(string $key, mixed $value): void
    {
        $method = 'set' . str_replace('_', '', ucwords($key, '_'));
        if (method_exists($this, $method)) call_user_func([$this, $method], $value);
    }


    /**
     * @return string
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return self
     */
    public function setId(?string $id = null): self
    {
        $this->id = $id ?: (string) Uuid::uuid4();
        return $this;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return self
     */
    public function setName($name = null): self
    {
        if ($name) $this->name = $name;
        return $this;
    }

    /**
     * @return DateTimeInterface
     */
    public function getCreatedAt(): DateTimeInterface
    {
        return $this->created_at;
    }

    /**
     * @param DateTimeInterface|string|null $created_at
     * @return self
     */
    public function setCreatedAt(DateTimeInterface|string|null $created_at = null): self
    {
        if (!$created_at) $created_at = Carbon::now();
        $this->created_at = $this->asDateTime($created_at);
        return $this;
    }

    /**
     * @return integer
     */
    public function getVersion(): int
    {
        return $this->version;
    }

    /**
     * @param integer $version
     */
    public function setVersion(?int $version = 0): void
    {
        $this->version = $version ?: 1;
    }

    /**
     * @return string
     */
    public function getAggregateId(): string
    {
        return $this->aggregate_id;
    }

    /**
     * @param string $aggregate_id
     */
    public function setAggregateId(?string $aggregate_id = null): void
    {
        if ($aggregate_id !== null) {
            $this->aggregate_id = $aggregate_id;
        }
    }

    /**
     * @return string
     */
    public function getAggregateType(): string
    {
        return $this->aggregate_type;
    }

    /**
     * @param string|null $aggregate_type
     * @return void
     */
    public function setAggregateType(?string $aggregate_type = null): void
    {
        if ($aggregate_type !== null) {
            $this->aggregate_type = $aggregate_type;
        }
    }

    /**
     * @return int
     */
    public function getAggregateVersion(): int
    {
        return $this->aggregate_version;
    }

    /**
     * @param int|null $aggregate_version
     * @return void
     */
    public function setAggregateVersion(?int $aggregate_version = null): void
    {
        $this->aggregate_version = intval($aggregate_version);
    }

    /**
     * @return Data
     */
    public function getPayload(): Data
    {
        return $this->payload;
    }

    /**
     * @param array|object $payload
     * @return void
     */
    public function setPayload(mixed $payload = null): void
    {
        $this->fillData('payload', $payload);
    }

    /**
     * @return Data
     */
    public function getMetadata(): Data
    {
        return $this->metadata;
    }

    /**
     * @param array|object $metadata
     */
    public function setMetadata(mixed $metadata = null): void
    {
        $this->fillData('metadata', $metadata);
    }

    /**
     * Fills payload or metadata.
     * @param string $target
     * @param mixed|null $payload
     * @return void
     */
    protected function fillData(string $target, mixed $payload = null): void
    {
        if (!$this->{$target}) $this->{$target} = new Data();

        $payload = $payload ?: [];

        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        } 

        foreach ($payload as $key => $value) {
            $this->{$target}->{$key} = $value;
        }
    }


    /**
     * Encode the given value as JSON.
     * @param  mixed  $value
     * @return string
     */
    public function toJson($value)
    {
        return json_encode($value);
    }

    /**
     * Decode the given JSON back into an array or object.
     * @param string $value
     * @param bool $as_object
     * @return mixed
     */
    public function fromJson(string $value, bool $as_object = false): mixed
    {
        return json_decode($value, ! $as_object);
    }

    /**
     * Return a timestamp as DateTime object.
     * @param mixed|null $value
     * @return Carbon
     */
    protected function asDateTime(mixed $value = null): DateTimeInterface
    {
        if (!$value) $value = Carbon::now();

        if ($value instanceof Carbon) {
            return $value;
        }
        if ($value instanceof DateTimeInterface) {
            return new Carbon($value->format('Y-m-d H:i:s.u'), $value->getTimezone());
        }
        if (is_numeric($value)) {
            return Carbon::createFromTimestamp($value);
        }
        return Carbon::parse($value);
    }

    /**
     * Convert a DateTime to a storable string.
     */
    public function fromDateTime(mixed $value): string
    {
        return $this->asDateTime($value);
    }

    /**
     * Convert the instance to an array.
     * @return array
     */
    public function toArray(): array
    {
        $properties = get_object_vars($this);
        return array_map(function ($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s.u');
            }
            elseif ($value instanceof JsonSerializable) {
                return $value->jsonSerialize();
            }  
            elseif (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            } 
            return $value;
        }, $properties);
    }

    /**
     * Convert the instance to an array.
     * @return array
     */
    public function toSqlData(): array
    {
        $properties = get_object_vars($this);
        return array_map(function ($value) {
            if ($value instanceof DateTimeInterface) {
                return $value->format('Y-m-d H:i:s.u');
            }
            elseif ($value instanceof JsonSerializable) {
                return json_encode($value);
            }  
            elseif (is_object($value) || is_array($value)) {
                return json_encode($value);
            }
            return $value;
        }, $properties);
    }

    /**
     * Serialize event to JSON
     * @return string 
     */
    public function __toString()
    {
        return $this->toJson($this->toArray());
    }    
}