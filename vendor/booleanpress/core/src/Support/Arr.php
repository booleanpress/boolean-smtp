<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Support;

/**
 * Array Helper
 *
 * Utility methods for working with arrays.
 */
class Arr
{
    /**
     * Get an item from an array using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string|null $key
     * @param mixed $default
     * @return mixed
     */
    public static function get(array $array, ?string $key, mixed $default = null): mixed
    {
        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        if (!str_contains($key, '.')) {
            return $array[$key] ?? $default;
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Set an array item to a given value using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string $key
     * @param mixed $value
     * @return array<string, mixed>
     */
    public static function set(array &$array, string $key, mixed $value): array
    {
        $keys = explode('.', $key);

        foreach ($keys as $i => $k) {
            if (count($keys) === 1) {
                break;
            }

            unset($keys[$i]);

            if (!isset($array[$k]) || !is_array($array[$k])) {
                $array[$k] = [];
            }

            $array = &$array[$k];
        }

        $array[array_shift($keys)] = $value;

        return $array;
    }

    /**
     * Check if an item exists in an array using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string|array<string> $keys
     * @return bool
     */
    public static function has(array $array, string|array $keys): bool
    {
        $keys = (array) $keys;

        if (empty($array) || empty($keys)) {
            return false;
        }

        foreach ($keys as $key) {
            $subArray = $array;

            if (array_key_exists($key, $array)) {
                continue;
            }

            foreach (explode('.', $key) as $segment) {
                if (is_array($subArray) && array_key_exists($segment, $subArray)) {
                    $subArray = $subArray[$segment];
                } else {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Remove one or many array items from a given array using "dot" notation.
     *
     * @param array<string, mixed> $array
     * @param string|array<string> $keys
     */
    public static function forget(array &$array, string|array $keys): void
    {
        $keys = (array) $keys;

        foreach ($keys as $key) {
            if (array_key_exists($key, $array)) {
                unset($array[$key]);
                continue;
            }

            $parts = explode('.', $key);
            $arr = &$array;

            while (count($parts) > 1) {
                $part = array_shift($parts);
                if (isset($arr[$part]) && is_array($arr[$part])) {
                    $arr = &$arr[$part];
                } else {
                    continue 2;
                }
            }

            unset($arr[array_shift($parts)]);
        }
    }

    /**
     * Get a subset of the items from the given array.
     *
     * @param array<string, mixed> $array
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Get all of the given array except for a specified array of keys.
     *
     * @param array<string, mixed> $array
     * @param array<string> $keys
     * @return array<string, mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Get the first element in an array passing a given truth test.
     *
     * @param array<mixed> $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public static function first(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            if (empty($array)) {
                return $default;
            }
            return reset($array);
        }

        foreach ($array as $key => $value) {
            if ($callback($value, $key)) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Get the last element in an array passing a given truth test.
     *
     * @param array<mixed> $array
     * @param callable|null $callback
     * @param mixed $default
     * @return mixed
     */
    public static function last(array $array, ?callable $callback = null, mixed $default = null): mixed
    {
        if ($callback === null) {
            return empty($array) ? $default : end($array);
        }

        return static::first(array_reverse($array, true), $callback, $default);
    }

    /**
     * Flatten a multi-dimensional array into a single level.
     *
     * @param array<mixed> $array
     * @param int $depth
     * @return array<mixed>
     */
    public static function flatten(array $array, int $depth = PHP_INT_MAX): array
    {
        $result = [];

        foreach ($array as $item) {
            if (!is_array($item)) {
                $result[] = $item;
            } elseif ($depth === 1) {
                $result = array_merge($result, array_values($item));
            } else {
                $result = array_merge($result, static::flatten($item, $depth - 1));
            }
        }

        return $result;
    }

    /**
     * Flatten a multi-dimensional associative array with dots.
     *
     * @param array<string, mixed> $array
     * @param string $prepend
     * @return array<string, mixed>
     */
    public static function dot(array $array, string $prepend = ''): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            if (is_array($value) && !empty($value)) {
                $results = array_merge($results, static::dot($value, $prepend . $key . '.'));
            } else {
                $results[$prepend . $key] = $value;
            }
        }

        return $results;
    }

    /**
     * Convert a flatten "dot" notation array into an expanded array.
     *
     * @param array<string, mixed> $array
     * @return array<string, mixed>
     */
    public static function undot(array $array): array
    {
        $results = [];

        foreach ($array as $key => $value) {
            static::set($results, $key, $value);
        }

        return $results;
    }

    /**
     * Pluck an array of values from an array.
     *
     * @param array<mixed> $array
     * @param string $value
     * @param string|null $key
     * @return array<mixed>
     */
    public static function pluck(array $array, string $value, ?string $key = null): array
    {
        $results = [];

        foreach ($array as $item) {
            $itemValue = static::get($item, $value);

            if ($key === null) {
                $results[] = $itemValue;
            } else {
                $itemKey = static::get($item, $key);
                $results[$itemKey] = $itemValue;
            }
        }

        return $results;
    }

    /**
     * Filter the array using the given callback.
     *
     * @param array<mixed> $array
     * @param callable $callback
     * @return array<mixed>
     */
    public static function where(array $array, callable $callback): array
    {
        return array_filter($array, $callback, ARRAY_FILTER_USE_BOTH);
    }

    /**
     * Filter items where the value is not null.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function whereNotNull(array $array): array
    {
        return static::where($array, fn ($value) => $value !== null);
    }

    /**
     * Shuffle the given array and return the result.
     *
     * @param array<mixed> $array
     * @return array<mixed>
     */
    public static function shuffle(array $array): array
    {
        shuffle($array);
        return $array;
    }

    /**
     * Get one or a specified number of random values from an array.
     *
     * @param array<mixed> $array
     * @param int|null $number
     * @return mixed
     */
    public static function random(array $array, ?int $number = null): mixed
    {
        $count = count($array);

        if ($number === null) {
            return $array[array_rand($array)];
        }

        if ($number >= $count) {
            return static::shuffle($array);
        }

        $keys = array_rand($array, $number);
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * If the given value is not an array and not null, wrap it in one.
     *
     * @param mixed $value
     * @return array<mixed>
     */
    public static function wrap(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return is_array($value) ? $value : [$value];
    }

    /**
     * Determine if the given value is an array accessible.
     */
    public static function accessible(mixed $value): bool
    {
        return is_array($value) || $value instanceof \ArrayAccess;
    }

    /**
     * Determine if the given key exists in the provided array.
     *
     * @param \ArrayAccess|array<mixed> $array
     * @param string|int $key
     */
    public static function exists(\ArrayAccess|array $array, string|int $key): bool
    {
        if ($array instanceof \ArrayAccess) {
            return $array->offsetExists($key);
        }

        return array_key_exists($key, $array);
    }

    /**
     * Push an item onto the beginning of an array.
     *
     * @param array<mixed> $array
     * @param mixed $value
     * @param mixed $key
     * @return array<mixed>
     */
    public static function prepend(array $array, mixed $value, mixed $key = null): array
    {
        if ($key === null) {
            array_unshift($array, $value);
        } else {
            $array = [$key => $value] + $array;
        }

        return $array;
    }

    /**
     * Get a value from the array, and remove it.
     *
     * @param array<string, mixed> $array
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    public static function pull(array &$array, string $key, mixed $default = null): mixed
    {
        $value = static::get($array, $key, $default);
        static::forget($array, $key);

        return $value;
    }

    /**
     * Sort the array using the given callback or "dot" notation.
     *
     * @param array<mixed> $array
     * @param callable|string|null $callback
     * @param bool $descending
     * @return array<mixed>
     */
    public static function sortBy(array $array, callable|string|null $callback = null, bool $descending = false): array
    {
        if ($callback === null) {
            $descending ? arsort($array) : asort($array);
            return $array;
        }

        $results = [];
        foreach ($array as $key => $value) {
            $results[$key] = is_callable($callback)
                ? $callback($value, $key)
                : static::get($value, $callback);
        }

        $descending ? arsort($results) : asort($results);

        $sorted = [];
        foreach (array_keys($results) as $key) {
            $sorted[$key] = $array[$key];
        }

        return $sorted;
    }

    /**
     * Check if an array is associative.
     *
     * @param array<mixed> $array
     */
    public static function isAssoc(array $array): bool
    {
        $keys = array_keys($array);
        return array_keys($keys) !== $keys;
    }

    /**
     * Check if an array is a list (sequential numeric keys starting from 0).
     *
     * @param array<mixed> $array
     */
    public static function isList(array $array): bool
    {
        return array_is_list($array);
    }
}
