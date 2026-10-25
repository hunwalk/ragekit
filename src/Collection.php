<?php

namespace RageKit;

/**
 * An ordered list of objects of one class, named by the subclass's OF constant:
 *
 *     final class Devices extends Collection { public const OF = Device::class; }
 *
 * Templates loop over it (@foreach ($devices as $device)) and read the item class's typed public
 * members ({{ $device->name }}); OF is how the compiler knows them. Change it with add(), [] =,
 * set(), remove() or clear(); later items move up when one is removed.
 */
abstract class Collection implements \IteratorAggregate, \Countable, \ArrayAccess
{
    /** The item class (override it). */
    public const OF = '';

    /** @var list<object> */
    private array $items = [];

    /** @param list<object> $items */
    public function __construct(array $items = [])
    {
        foreach ($items as $item) {
            $this->add($item);
        }
    }

    public function add(object $item): void
    {
        $this->check($item);
        $this->items[] = $item;
    }

    /** Replaces item $index; $index count() appends. */
    public function set(int $index, object $item): void
    {
        if ($index === count($this->items)) {
            $this->add($item);
            return;
        }
        $this->range($index);
        $this->check($item);
        $this->items[$index] = $item;
    }

    public function get(int $index): object
    {
        $this->range($index);
        return $this->items[$index];
    }

    /** Removes item $index; the items after it move up. */
    public function remove(int $index): void
    {
        $this->range($index);
        array_splice($this->items, $index, 1);
    }

    public function clear(): void
    {
        $this->items = [];
    }

    /** @return list<object> */
    public function all(): array
    {
        return $this->items;
    }

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    public function count(): int
    {
        return count($this->items);
    }

    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return is_int($offset) && $offset >= 0 && $offset < count($this->items);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->get((int) $offset);
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if (!is_object($value)) {
            throw new \InvalidArgumentException(static::class . ' holds objects, not ' . gettype($value));
        }
        if ($offset === null) {
            $this->add($value);
            return;
        }
        $this->set((int) $offset, $value);
    }

    public function offsetUnset(mixed $offset): void
    {
        $this->remove((int) $offset);
    }

    private function check(object $item): void
    {
        if (static::OF !== '' && !is_a($item, static::OF)) {
            throw new \InvalidArgumentException(static::class . ' holds ' . static::OF . ', not ' . get_class($item));
        }
    }

    private function range(int $index): void
    {
        if ($index < 0 || $index >= count($this->items)) {
            throw new \OutOfRangeException(static::class . " has no item $index (it has " . count($this->items) . ')');
        }
    }
}
