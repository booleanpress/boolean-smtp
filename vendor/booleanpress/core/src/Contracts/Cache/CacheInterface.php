<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts\Cache;

use DateInterval;

/**
 * General data cache interface.
 *
 * Mirrors the PSR-16 simple-cache signatures without depending on the PSR package.
 */
interface CacheInterface
{
    /**
     * Fetch a value from the cache.
     *
     * @param string $key     The unique key of this item in the cache
     * @param mixed  $default Default value to return if the key does not exist
     * @return mixed The value of the item from the cache, or $default in case of cache miss
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Persist data in the cache, uniquely referenced by a key with an optional expiration TTL.
     *
     * @param string                $key   The key of the item to store
     * @param mixed                 $value The value of the item to store, must be serializable
     * @param null|int|DateInterval $ttl   Optional TTL; null means the driver default
     * @return bool True on success and false on failure
     */
    public function set(string $key, mixed $value, null|int|DateInterval $ttl = null): bool;

    /**
     * Delete an item from the cache by its unique key.
     *
     * @param string $key The unique cache key of the item to delete
     * @return bool True if the item was successfully removed, false otherwise
     */
    public function delete(string $key): bool;

    /**
     * Wipe clean the entire cache's keys.
     *
     * @return bool True on success and false on failure
     */
    public function clear(): bool;

    /**
     * Obtain multiple cache items by their unique keys.
     *
     * @param iterable<string> $keys    A list of keys that can be obtained in a single operation
     * @param mixed            $default Default value to return for keys that do not exist
     * @return iterable<string, mixed> A list of key => value pairs
     */
    public function getMultiple(iterable $keys, mixed $default = null): iterable;

    /**
     * Persist a set of key => value pairs in the cache, with an optional TTL.
     *
     * @param iterable<string, mixed> $values A list of key => value pairs
     * @param null|int|DateInterval   $ttl    Optional TTL; null means the driver default
     * @return bool True on success and false on failure
     */
    public function setMultiple(iterable $values, null|int|DateInterval $ttl = null): bool;

    /**
     * Delete multiple cache items in a single operation.
     *
     * @param iterable<string> $keys A list of string-based keys to be deleted
     * @return bool True if the items were successfully removed, false otherwise
     */
    public function deleteMultiple(iterable $keys): bool;

    /**
     * Determine whether an item is present in the cache.
     *
     * @param string $key The cache item key
     */
    public function has(string $key): bool;
}
