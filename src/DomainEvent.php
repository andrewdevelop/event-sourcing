<?php 

namespace Core\EventSourcing;

use Core\Contracts\Event;
use Ramsey\Uuid\Uuid;
use Carbon\Carbon;
use DateTimeInterface;
use InvalidArgumentException;
use JsonSerializable;

class DomainEvent implements Event
{
	/**
	 * UUID 4
	 * @var string
	 */
	protected $id;

	/**
	 * Event name
	 * @var string
	 */
	protected $name;

	/**
	 * Event versioning.
	 * @var integer
	 */
	protected $version;

	/**
	 * Aggregate UUID
	 * @var string
	 */
	protected $aggregate_id;

	/**
	 * Class of aggreagate root
	 * @var string
	 */
	protected $aggregate_type;

	/**
	 * Aggregate version
	 * @var int
	 */
	protected $aggregate_version;

	/**
	 * Event payload
	 * @var array|object
	 */
	protected $payload;

	/**
	 * Event meta
	 * @var array|object
	 */
	protected $metadata;

	/**
	 * Event created at
	 * @var Carbon
	 */
	protected $created_at;


	/**
	 * Instaniate a new event.
	 * @param array|object $data 
	 */
	public function __construct($data = [])
	{
		$keys = array_keys(get_object_vars($this));
		foreach ($keys as $key) {
			$value = isset($data[$key]) ? $data[$key] : null;
			$this->setAttribute($key, $value);
		}
		if (!$this->name) {
			throw new InvalidArgumentException('Event name not defined.');
		}
	}

	/**
	 * Magic getter.
	 * @param  string $key 
	 * @return mixed      
	 */
	public function __get($key)
	{
		if (property_exists($this, $key)) return $this->getAttribute($key);
	}

	/**
	 * Magic setter.
	 * @param strring $key   
	 * @param mixed $value 
	 */
	public function __set($key, $value)
	{
		if (property_exists($this, $key)) $this->setAttribute($key, $value);
	}


	/**
	 * Get attribute.
	 * @param  string $key 
	 * @return mixed
	 */
	protected function getAttribute($key)
	{
		$method = 'get' . str_replace('_', '', ucwords($key, '_'));
		if (method_exists($this, $method)) return call_user_func([$this, $method]);
		return $this;
	}

	/**
	 * Set a given attribute.
     * @param  string  $key
     * @param  mixed  $value
     * @return self
	 */
	protected function setAttribute($key, $value = null)
	{
		$method = 'set' . str_replace('_', '', ucwords($key, '_'));
		if (method_exists($this, $method)) call_user_func([$this, $method], $value);
		return $this;
	}


    /**
     * @return string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @param string $id
     * @return self
     */
    public function setId($id)
    {
        $this->id = $id ? $id : (string) Uuid::uuid4();
        return $this;
    }

    /**
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * @param string $name
     *
     * @return self
     */
    public function setName($name = null)
    {
    	if ($name) $this->name = $name;
        return $this;
    }

    /**
     * @return DateTimeInterface
     */
    public function getCreatedAt()
    {
        return $this->created_at;
    }

    /**
     * @param Carbon $created_at
     * @return self
     */
    public function setCreatedAt($created_at = null)
    {
    	if (!$created_at) $created_at = Carbon::now();
        $this->created_at = $this->fromDateTime($created_at);
        return $this;
    }

    /**
     * @return integer
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @param integer $version
     * @return self
     */
    public function setVersion($version = 0)
    {
        $this->version = intval($version ? $version : 1);
        return $this;
    }

    /**
     * @return string
     */
    public function getAggregateId()
    {
        return $this->aggregate_id;
    }

    /**
     * @param string $aggregate_id
     * @return self
     */
    public function setAggregateId($aggregate_id = null)
    {
        $this->aggregate_id = $aggregate_id;
        return $this;
    }

    /**
     * @return string
     */
    public function getAggregateType()
    {
        return $this->aggregate_type;
    }

    /**
     * @param string $aggregate_type
     * @return self
     */
    public function setAggregateType($aggregate_type = null)
    {
        $this->aggregate_type = $aggregate_type;
        return $this;
    }

    /**
     * @return int
     */
    public function getAggregateVersion()
    {
        return $this->aggregate_version;
    }

    /**
     * @param int $aggregate_version
     * @return self
     */
    public function setAggregateVersion($aggregate_version = null)
    {
        $this->aggregate_version = intval($aggregate_version);
        return $this;
    }

    /**
     * @return array|object
     */
    public function getPayload()
    {
        return $this->payload;
    }

    /**
     * @param array|object $payload
     * @return self
     */
    public function setPayload($payload = null)
    {
        $payload = $payload ? $payload : [];
    	if (!$this->payload) {
    		$this->payload = new Data($payload);
    	} elseif (is_array($payload)) {
    		foreach ($payload as $key => $value) $this->payload->{$key} = $value;
    	}
        return $this;
    }

    /**
     * @return array|object
     */
    public function getMetadata()
    {
        return $this->metadata;
    }

    /**
     * @param array|object $metadata
     * @return self
     */
    public function setMetadata($metadata = null)
    {
        $metadata = $metadata ? $metadata : [];
    	if (!$this->metadata) {
    		$this->metadata = new Data($metadata);
    	} elseif (is_array($metadata)) {
    		foreach ($metadata as $key => $value) $this->metadata->{$key} = $value;
    	}
        return $this;
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
     * @param  string  $value
     * @param  bool  $as_object
     * @return mixed
     */
    public function fromJson($value, $as_object = false)
    {
        return json_decode($value, ! $as_object);
    }

	/**
     * Return a timestamp as DateTime object.
     * @param  mixed  $value
     * @return \Illuminate\Support\Carbon
     */
    protected function asDateTime($value = null)
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
        return Carbon::createFromFormat('Y-m-d H:i:s.u', $value);
    }

    /**
     * Convert a DateTime to a storable string.
     * @param  \DateTime|int  $value
     * @return string
     */
    public function fromDateTime($value)
    {
        return $this->asDateTime($value);
    }

    /**
     * Convert the instance to an array.
     * @return array
     */
    public function toArray()
    {
		return array_map(function ($value) {
			if ($value instanceof JsonSerializable) {
				return $value->jsonSerialize();
			} elseif ($value instanceof DateTimeInterface) {
				return $value->format('Y-m-d H:i:s.u');
			} elseif (is_object($value) && method_exists($value, 'toArray')) {
				return $value->toArray();
			} 
            return $value;
        }, get_object_vars($this));
    }

    /**
     * Convert the instance to an array.
     * @return array
     */
    public function toSqlData()
    {
		return array_map(function ($value) {
			if ($value instanceof JsonSerializable) {
				return $value->toJson();
			} elseif ($value instanceof DateTimeInterface) {
				return $value->format('Y-m-d H:i:s.u');
			} elseif (is_object($value) || is_array($value)) {
				return json_encode($value);
			}
            return $value;
        }, get_object_vars($this));
    }

    /**
     * Serialize event to JSON
     * @return string 
     */
    public function __toString()
    {
    	return $this->toJson($this->toArray(), true);
    }    
}