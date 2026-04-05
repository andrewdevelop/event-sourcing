<?php 

namespace Core\EventSourcing;

use ArrayAccess;
use ArrayIterator;
use IteratorAggregate;
use Exception;
use JsonSerializable;
use Traversable;

final class Data implements ArrayAccess, IteratorAggregate, JsonSerializable
{
    /**
     * Create a new collection.
     *
     * @param  mixed  $attributes
     * @return void
     */
    public function __construct(
        protected array $attributes = [],
    )
    {
    }

    /**
     * Dynamically access attributes.
     * @param  string  $key
     * @return mixed
     * @throws Exception
     */
    public function __get(string $key): mixed
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
     * @throws Exception
     */
    public function __set(string $key, mixed $value): void
    {
        $this->offsetSet($key, $value);
    }

    /**
     * Get all the attributes in the collection.
     * @return array
     */
    public function toArray(): array
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
     * @return mixed
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    } 

    /**
     * Get an iterator for the attributes.
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->attributes);
    }

    /**
     * Determine if an attribute exists at an offset.
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists(mixed $offset): bool
    {
        return array_key_exists($offset, $this->attributes);
    }

    /**
     * Get an attribute at a given offset.
     * @param  mixed  $offset
     * @return mixed
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->attributes[$offset];
    }

    /**
     * Set the attribute at a given offset.
     * @param mixed $offset
     * @param mixed $value
     * @return void
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->attributes[$offset] = $value;
    }

    /**
     * Unset the attribute at a given offset.
     * @param mixed $offset
     * @return void
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->attributes[$offset]);
    }

}