<?php

declare(strict_types=1);

namespace Orboni;

/**
 * An object the API answered: a checkout session, a refund, an event…
 * Read its fields as properties or as array keys, nested objects alike:
 *
 *     $session->url;  $session['url'];  $event->data->object->id;
 *
 * A field the answer does not have reads as null. JSON lists stay PHP
 * lists. It is read-only; toArray() gives plain arrays, and json_encode()
 * gives the JSON back.
 *
 * @implements \ArrayAccess<array-key, mixed>
 * @implements \IteratorAggregate<array-key, mixed>
 */
class OrboniObject implements \ArrayAccess, \IteratorAggregate, \JsonSerializable
{
    /** @var array<array-key, mixed> */
    private array $values;

    /**
     * @param  array<array-key, mixed>  $values  fields already converted (nested objects as OrboniObject)
     */
    final public function __construct(array $values = [])
    {
        $this->values = $values;
    }

    /**
     * From a JSON object decoded by json_decode() without $associative: its
     * nested objects become OrboniObject, a list object (`"object": "list"`)
     * a Collection.
     */
    public static function constructFrom(\stdClass $object): static
    {
        $values = self::fields($object);
        if (static::class === self::class && ($values['object'] ?? null) === 'list') {
            /** @var static */
            return new Collection($values);
        }

        return new static($values);
    }

    /** @return array<array-key, mixed> */
    private static function fields(\stdClass $object): array
    {
        $values = [];
        foreach (get_object_vars($object) as $key => $value) {
            $values[$key] = self::convert($value);
        }

        return $values;
    }

    private static function convert(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $values = self::fields($value);

            return ($values['object'] ?? null) === 'list' ? new Collection($values) : new self($values);
        }
        if (is_array($value)) {
            return array_map(static fn (mixed $item): mixed => self::convert($item), $value);
        }

        return $value;
    }

    public function __get(string $name): mixed
    {
        return $this->values[$name] ?? null;
    }

    public function __isset(string $name): bool
    {
        return isset($this->values[$name]);
    }

    public function __set(string $name, mixed $value): void
    {
        throw self::readOnly();
    }

    public function __unset(string $name): void
    {
        throw self::readOnly();
    }

    public function offsetExists(mixed $offset): bool
    {
        return (is_string($offset) || is_int($offset)) && isset($this->values[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return is_string($offset) || is_int($offset) ? $this->values[$offset] ?? null : null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        throw self::readOnly();
    }

    public function offsetUnset(mixed $offset): void
    {
        throw self::readOnly();
    }

    /**
     * The fields, field name => value.
     *
     * @return \ArrayIterator<array-key, mixed>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->values);
    }

    /**
     * The fields as plain arrays, all the way down.
     *
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return self::plain($this->values);
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return array<array-key, mixed>
     */
    private static function plain(array $values): array
    {
        return array_map(static fn (mixed $value): mixed => match (true) {
            $value instanceof self => $value->toArray(),
            is_array($value) => self::plain($value),
            default => $value,
        }, $values);
    }

    /** Always a JSON object, `{}` when it has no fields. */
    public function jsonSerialize(): \stdClass
    {
        return (object) $this->values;
    }

    /** @return array<array-key, mixed> */
    public function __debugInfo(): array
    {
        return $this->values;
    }

    /** @return array<array-key, mixed> */
    public function __serialize(): array
    {
        return $this->values;
    }

    /** @param  array<array-key, mixed>  $data */
    public function __unserialize(array $data): void
    {
        $this->values = $data;
    }

    private static function readOnly(): \LogicException
    {
        return new \LogicException('An answer of Orboni is read-only: call toArray() for a copy you can change.');
    }
}
