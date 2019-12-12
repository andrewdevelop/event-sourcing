<?php 

namespace Core\EventSourcing;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Exception;
use JsonSerializable;

final class Data implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    /**
     * The attributes contained in the collection.
     * @var array
     */
    protected $attributes = [];

    /**
     * Create a new collection.
     *
     * @param  mixed  $attributes
     * @return void
     */
    public function __construct($attributes = [])
    {
        $this->attributes = $attributes;
    }

    /**
     * Dynamically access attributes.
     * @param  string  $key
     * @return mixed
     * @throws \Exception
     */
    public function __get($key)
    {
        if ($this->offsetExists($key)) {
            return $this->offsetGet($key);
        } else {
            return null;
        }
    }

    /**
     * Dynamically set attribute.
     * @param  string  $key
     * @param  mixed   $value
     * @throws \Exception
     */
    public function __set($key, $value)
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Get all of the attributes in the collection.
     * @return array
     */
    public function toArray()
    {
        return array_map(function ($attribute) {
            if ($attribute instanceof \DateTimeInterface) {
                return $attribute->format('Y-m-d H:i:s.u');
            } 
            elseif ($attribute instanceof \JsonSerializable) {
                return $attribute->jsonSerialize();
            } 
            elseif (is_iterable($attribute)) {
                return (array) $attribute;
            }
            return $attribute;
        }, $this->attributes);
    }

    /**
     * Specify data which should be serialized to JSON
     * @return array
     */
    public function jsonSerialize() {
        return $this->toArray();
    } 

    /**
     * Get an iterator for the attributes.
     * @return \ArrayIterator
     */
    public function getIterator()
    {
        return new ArrayIterator($this->attributes);
    }

    /**
     * Determine if an attribute exists at an offset.
     * @param  mixed  $key
     * @return bool
     */
    public function offsetExists($key)
    {
        return array_key_exists($key, $this->attributes);
    }

    /**
     * Get an attribute at a given offset.
     * @param  mixed  $key
     * @return mixed
     */
    public function offsetGet($key)
    {
        return $this->attributes[$key];
    }

    /**
     * Set the attribute at a given offset.
     * @param  mixed  $key
     * @param  mixed  $value
     * @return void
     */
    public function offsetSet($key, $value)
    {
        if (is_null($key)) {
            $this->attributes[] = $value;
        } else {
            $this->attributes[$key] = $value;
        }
    }

    /**
     * Unset the attribute at a given offset.
     * @param  string  $key
     * @return void
     */
    public function offsetUnset($key)
    {
        unset($this->attributes[$key]);
    }

}