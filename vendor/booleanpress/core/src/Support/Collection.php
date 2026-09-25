<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Support;

use ArrayAccess;
use ArrayIterator;
use Countable;
use IteratorAggregate;
use JsonSerializable;
use Traversable;
use function BooleanSmtp\Core\data_get;

/**
 * Collection
 *
 * A fluent, convenient wrapper for working with arrays of data.
 * Inspired by Laravel Collections.
 *
 * @template TKey of array-key
 * @template TValue
 * @implements ArrayAccess<TKey, TValue>
 * @implements IteratorAggregate<TKey, TValue>
 */
class Collection implements ArrayAccess, Countable, IteratorAggregate, JsonSerializable
{
    /**
     * The items contained in the collection.
     *
     * @var array<TKey, TValue>
     */
    protected array $items = [];

    /**
     * Create a new collection instance.
     *
     * @param iterable<TKey, TValue> $items
     */
    public function __construct(iterable $items = [])
    {
        $this->items = $this->getArrayableItems($items);
    }

    /**
     * Create a new collection instance.
     *
     * @param iterable<TKey, TValue> $items
     * @return static<TKey, TValue>
     */
    public static function make(iterable $items = []): static
    {
        return new static($items);
    }

    /**
     * Create a collection by wrapping a value.
     *
     * @param mixed $value
     * @return static
     */
    public static function wrap(mixed $value): static
    {
        if ($value instanceof static) {
            return $value;
        }

        return new static(is_array($value) ? $value : [$value]);
    }

    /**
     * Get all items in the collection.
     *
     * @return array<TKey, TValue>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Get an item from the collection by key.
     *
     * @param TKey $key
     * @param TValue|null $default
     * @return TValue|null
     */
    public function get(mixed $key, mixed $default = null): mixed
    {
        if ($this->has($key)) {
            return $this->items[$key];
        }

        return $default instanceof \Closure ? $default() : $default;
    }

    /**
     * Put an item in the collection by key.
     *
     * @param TKey $key
     * @param TValue $value
     * @return static
     */
    public function put(mixed $key, mixed $value): static
    {
        $this->items[$key] = $value;
        return $this;
    }

    /**
     * Check if an item exists in the collection by key.
     *
     * @param TKey $key
     */
    public function has(mixed $key): bool
    {
        return array_key_exists($key, $this->items);
    }

    /**
     * Get the first item from the collection.
     *
     * @param callable|null $callback
     * @param TValue|null $default
     * @return TValue|null
     */
    public function first(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($this->items)) {
                return $default;
            }
            return reset($this->items);
        }

        foreach ($this->items as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the last item from the collection.
     *
     * @param callable|null $callback
     * @param TValue|null $default
     * @return TValue|null
     */
    public function last(?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($this->items)) {
                return $default;
            }
            return end($this->items);
        }

        return $this->reverse()->first($callback, $default);
    }

    /**
     * Run a map over each of the items.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function map(callable $callback): static
    {
        $keys = array_keys($this->items);
        $items = array_map($callback, $this->items, $keys);

        return new static(array_combine($keys, $items));
    }

    /**
     * Run a filter over each of the items.
     *
     * @param callable(TValue, TKey): bool|null $callback
     * @return static
     */
    public function filter(?callable $callback = null): static
    {
        if ($callback) {
            return new static(array_filter($this->items, $callback, ARRAY_FILTER_USE_BOTH));
        }

        return new static(array_filter($this->items));
    }

    /**
     * Filter items by the given key value pair.
     *
     * @param string $key
     * @param mixed $operator
     * @param mixed $value
     * @return static
     */
    public function where(string $key, mixed $operator = null, mixed $value = null): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        return $this->filter(function ($item) use ($key, $operator, $value) {
            $itemValue = data_get($item, $key);

            return match ($operator) {
                '=' => $itemValue == $value,
                '===' => $itemValue === $value,
                '!=' => $itemValue != $value,
                '!==' => $itemValue !== $value,
                '>' => $itemValue > $value,
                '>=' => $itemValue >= $value,
                '<' => $itemValue < $value,
                '<=' => $itemValue <= $value,
                default => $itemValue == $value,
            };
        });
    }

    /**
     * Filter items where the key exists.
     *
     * @param string $key
     * @return static
     */
    public function whereNotNull(string $key): static
    {
        return $this->filter(fn ($item) => data_get($item, $key) !== null);
    }

    /**
     * Reduce the collection to a single value.
     *
     * @param callable(mixed, TValue, TKey): mixed $callback
     * @param mixed $initial
     * @return mixed
     */
    public function reduce(callable $callback, mixed $initial = null): mixed
    {
        $result = $initial;

        foreach ($this->items as $key => $value) {
            $result = $callback($result, $value, $key);
        }

        return $result;
    }

    /**
     * Run a callback over each item.
     *
     * @param callable(TValue, TKey): mixed $callback
     * @return static
     */
    public function each(callable $callback): static
    {
        foreach ($this->items as $key => $item) {
            if ($callback($item, $key) === false) {
                break;
            }
        }

        return $this;
    }

    /**
     * Get the values of a given key.
     *
     * @param string $key
     * @return static
     */
    public function pluck(string $key): static
    {
        return $this->map(fn ($item) => data_get($item, $key));
    }

    /**
     * Key the collection by the given key.
     *
     * @param string|callable $keyBy
     * @return static
     */
    public function keyBy(string|callable $keyBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $resolvedKey = is_callable($keyBy) ? $keyBy($item, $key) : data_get($item, $keyBy);
            $results[$resolvedKey] = $item;
        }

        return new static($results);
    }

    /**
     * Group the collection by a given key.
     *
     * @param string|callable $groupBy
     * @return static
     */
    public function groupBy(string|callable $groupBy): static
    {
        $results = [];

        foreach ($this->items as $key => $item) {
            $groupKey = is_callable($groupBy) ? $groupBy($item, $key) : data_get($item, $groupBy);
            $results[$groupKey][] = $item;
        }

        return new static($results);
    }

    /**
     * Sort the collection.
     *
     * @param callable|null $callback
     * @return static
     */
    public function sort(?callable $callback = null): static
    {
        $items = $this->items;

        $callback
            ? uasort($items, $callback)
            : asort($items);

        return new static($items);
    }

    /**
     * Sort the collection by a given key.
     *
     * @param string|callable $key
     * @param bool $descending
     * @return static
     */
    public function sortBy(string|callable $key, bool $descending = false): static
    {
        $results = [];

        foreach ($this->items as $itemKey => $item) {
            $sortKey = is_callable($key) ? $key($item, $itemKey) : data_get($item, $key);
            $results[$itemKey] = $sortKey;
        }

        $descending ? arsort($results) : asort($results);

        $sorted = [];
        foreach (array_keys($results) as $k) {
            $sorted[$k] = $this->items[$k];
        }

        return new static($sorted);
    }

    /**
     * Reverse the collection.
     *
     * @return static
     */
    public function reverse(): static
    {
        return new static(array_reverse($this->items, true));
    }

    /**
     * Get unique items.
     *
     * @param string|callable|null $key
     * @return static
     */
    public function unique(string|callable|null $key = null): static
    {
        if ($key === null) {
            return new static(array_unique($this->items, SORT_REGULAR));
        }

        $seen = [];
        return $this->filter(function ($item) use ($key, &$seen) {
            $value = is_callable($key) ? $key($item) : data_get($item, $key);
            if (in_array($value, $seen, true)) {
                return false;
            }
            $seen[] = $value;
            return true;
        });
    }

    /**
     * Get the values of the collection.
     *
     * @return static
     */
    public function values(): static
    {
        return new static(array_values($this->items));
    }

    /**
     * Get the keys of the collection.
     *
     * @return static<int, TKey>
     */
    public function keys(): static
    {
        return new static(array_keys($this->items));
    }

    /**
     * Merge the collection with the given items.
     *
     * @param iterable $items
     * @return static
     */
    public function merge(iterable $items): static
    {
        return new static(array_merge($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Push one or more items onto the end of the collection.
     *
     * @param TValue ...$values
     * @return static
     */
    public function push(mixed ...$values): static
    {
        foreach ($values as $value) {
            $this->items[] = $value;
        }

        return $this;
    }

    /**
     * Get and remove the last item from the collection.
     *
     * @return TValue|null
     */
    public function pop(): mixed
    {
        return array_pop($this->items);
    }

    /**
     * Get and remove the first item from the collection.
     *
     * @return TValue|null
     */
    public function shift(): mixed
    {
        return array_shift($this->items);
    }

    /**
     * Slice the collection.
     *
     * @param int $offset
     * @param int|null $length
     * @return static
     */
    public function slice(int $offset, ?int $length = null): static
    {
        return new static(array_slice($this->items, $offset, $length, true));
    }

    /**
     * Take the first or last {$limit} items.
     *
     * @param int $limit
     * @return static
     */
    public function take(int $limit): static
    {
        if ($limit < 0) {
            return $this->slice($limit, abs($limit));
        }

        return $this->slice(0, $limit);
    }

    /**
     * Skip the first {$count} items.
     *
     * @param int $count
     * @return static
     */
    public function skip(int $count): static
    {
        return $this->slice($count);
    }

    /**
     * Chunk the collection into chunks of the given size.
     *
     * @param int $size
     * @return static
     */
    public function chunk(int $size): static
    {
        $chunks = [];

        foreach (array_chunk($this->items, $size, true) as $chunk) {
            $chunks[] = new static($chunk);
        }

        return new static($chunks);
    }

    /**
     * Flatten a multi-dimensional collection into a single level.
     *
     * @param int $depth
     * @return static
     */
    public function flatten(int $depth = PHP_INT_MAX): static
    {
        return new static($this->flattenArray($this->items, $depth));
    }

    /**
     * Flatten an array.
     *
     * @param array $array
     * @param int $depth
     * @return array
     */
    protected function flattenArray(array $array, int $depth): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, $this->flattenArray($item, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * Determine if the collection is empty.
     */
    public function isEmpty(): bool
    {
        return empty($this->items);
    }

    /**
     * Determine if the collection is not empty.
     */
    public function isNotEmpty(): bool
    {
        return !$this->isEmpty();
    }

    /**
     * Determine if an item exists in the collection.
     *
     * @param TValue|callable $value
     * @return bool
     */
    public function contains(mixed $value): bool
    {
        if (is_callable($value)) {
            foreach ($this->items as $key => $item) {
                if ($value($item, $key)) {
                    return true;
                }
            }
            return false;
        }

        return in_array($value, $this->items, true);
    }

    /**
     * Get the sum of the given values.
     *
     * @param string|callable|null $callback
     * @return int|float
     */
    public function sum(string|callable|null $callback = null): int|float
    {
        if ($callback === null) {
            return array_sum($this->items);
        }

        return $this->reduce(function ($result, $item) use ($callback) {
            $value = is_callable($callback) ? $callback($item) : data_get($item, $callback);
            return $result + ($value ?? 0);
        }, 0);
    }

    /**
     * Get the average value of a given key.
     *
     * @param string|callable|null $callback
     * @return int|float|null
     */
    public function avg(string|callable|null $callback = null): int|float|null
    {
        $count = $this->count();
        if ($count === 0) {
            return null;
        }

        return $this->sum($callback) / $count;
    }

    /**
     * Get the min value.
     *
     * @param string|callable|null $callback
     * @return mixed
     */
    public function min(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return min($this->items);
        }

        return $this->pluck($callback)->min();
    }

    /**
     * Get the max value.
     *
     * @param string|callable|null $callback
     * @return mixed
     */
    public function max(string|callable|null $callback = null): mixed
    {
        if ($callback === null) {
            return max($this->items);
        }

        return $this->pluck($callback)->max();
    }

    /**
     * Convert the collection to an array.
     *
     * @return array<TKey, TValue>
     */
    public function toArray(): array
    {
        return array_map(function ($value) {
            if ($value instanceof self) {
                return $value->toArray();
            }
            if (is_object($value) && method_exists($value, 'toArray')) {
                return $value->toArray();
            }
            return $value;
        }, $this->items);
    }

    /**
     * Convert the collection to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->jsonSerialize(), $options);
    }

    /**
     * Get the items that should be serialized to JSON.
     *
     * @return array<TKey, TValue>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    /**
     * Count the number of items in the collection.
     */
    public function count(): int
    {
        return count($this->items);
    }

    /**
     * Get an iterator for the items.
     *
     * @return ArrayIterator<TKey, TValue>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }

    /**
     * Determine if an item exists at an offset.
     */
    public function offsetExists(mixed $offset): bool
    {
        return $this->has($offset);
    }

    /**
     * Get an item at a given offset.
     *
     * @param TKey $offset
     * @return TValue|null
     */
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    /**
     * Set the item at a given offset.
     *
     * @param TKey|null $offset
     * @param TValue $value
     */
    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;
        } else {
            $this->items[$offset] = $value;
        }
    }

    /**
     * Unset the item at a given offset.
     *
     * @param TKey $offset
     */
    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }

    /**
     * Get arrayable items.
     *
     * @param iterable $items
     * @return array
     */
    /**
     * Get arrayable items.
     *
     * @param iterable $items
     * @return array
     */
    protected function getArrayableItems(iterable $items): array
    {
        if (is_array($items)) {
            return $items;
        }

        if ($items instanceof self) {
            return $items->all();
        }

        return iterator_to_array($items);
    }

    /**
     * Get only the items with the specified keys.
     *
     * @param array<TKey>|TKey ...$keys
     * @return static
     */
    public function only(mixed ...$keys): static
    {
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;

        return new static(array_intersect_key($this->items, array_flip($keys)));
    }

    /**
     * Get all items except for those with the specified keys.
     *
     * @param array<TKey>|TKey ...$keys
     * @return static
     */
    public function except(mixed ...$keys): static
    {
        $keys = is_array($keys[0] ?? null) ? $keys[0] : $keys;

        return new static(array_diff_key($this->items, array_flip($keys)));
    }

    /**
     * Concatenate values of a given key as a string.
     *
     * @param string|null $value
     * @param string|null $glue
     */
    public function implode(?string $value = null, ?string $glue = null): string
    {
        if ($value === null) {
            return implode($glue ?? '', $this->items);
        }

        return $this->pluck($value)->implode(null, $glue);
    }

    /**
     * Flip the keys and values.
     *
     * @return static
     */
    public function flip(): static
    {
        return new static(array_flip($this->items));
    }

    /**
     * Remove an item from the collection by key.
     *
     * @param TKey|array<TKey> $keys
     * @return static
     */
    public function forget(mixed $keys): static
    {
        $keys = is_array($keys) ? $keys : [$keys];

        $items = $this->items;
        foreach ($keys as $key) {
            unset($items[$key]);
        }

        return new static($items);
    }

    /**
     * Get the items that are not present in the given items.
     *
     * @param iterable $items
     * @return static
     */
    public function diff(iterable $items): static
    {
        return new static(array_diff($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Get the items whose keys are not present in the given items.
     *
     * @param iterable $items
     * @return static
     */
    public function diffKeys(iterable $items): static
    {
        return new static(array_diff_key($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Intersect the collection with the given items.
     *
     * @param iterable $items
     * @return static
     */
    public function intersect(iterable $items): static
    {
        return new static(array_intersect($this->items, $this->getArrayableItems($items)));
    }

    /**
     * Combine the values of the collection as keys with another array as values.
     *
     * @param iterable $values
     * @return static
     */
    public function combine(iterable $values): static
    {
        return new static(array_combine($this->items, $this->getArrayableItems($values)));
    }

    /**
     * Pad collection to the specified length.
     *
     * @param int $size
     * @param mixed $value
     * @return static
     */
    public function pad(int $size, mixed $value): static
    {
        return new static(array_pad($this->items, $size, $value));
    }

    /**
     * Get a random item from the collection.
     *
     * @param int|null $number
     * @return static|TValue|null
     */
    public function random(?int $number = null): mixed
    {
        if ($this->isEmpty()) {
            return $number === null ? null : new static([]);
        }

        $keys = array_rand($this->items, $number ?? 1);

        if ($number === null) {
            return $this->items[$keys];
        }

        return new static(array_intersect_key($this->items, array_flip((array) $keys)));
    }
}
