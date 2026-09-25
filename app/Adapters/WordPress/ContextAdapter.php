<?php
/**
 * WordPress implementation of the request context adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\ContextAdapterContract;

/**
 * Implements the context adapter contract using an in-memory array scoped to the current process.
 *
 * This provides a way to pass state through the mail pipeline, such as a connection ID forced by a
 * test email, without relying on a global hook or filter to communicate it.
 *
 * @since 1.0.0
 */
final class ContextAdapter implements ContextAdapterContract
{
    /**
     * Request-scoped context storage.
     *
     * Static so the values persist for the lifetime of a single request or CLI command.
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    private static array $context = [];

    /**
     * Store a context value under a key.
     *
     * @since 1.0.0
     *
     * @param string $key Context key
     * @param mixed $value Context value
     */
    public function set(string $key, mixed $value): void
    {
        self::$context[$key] = $value;
    }

    /**
     * Retrieve a stored context value.
     *
     * @since 1.0.0
     *
     * @param string $key Context key
     * @param mixed $default Default if not found
     * @return mixed The stored value, or $default when the key is not set.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return self::$context[$key] ?? $default;
    }

    /**
     * Determine whether a context key is set.
     *
     * @since 1.0.0
     *
     * @param string $key Context key
     * @return bool True when the key has a stored value.
     */
    public function has(string $key): bool
    {
        return isset(self::$context[$key]);
    }

    /**
     * Remove a single stored context value.
     *
     * @since 1.0.0
     *
     * @param string $key Context key
     */
    public function forget(string $key): void
    {
        unset(self::$context[$key]);
    }

    /**
     * Remove all stored context values.
     *
     * Called at the end of a request in a web context, or at the end of a command in a CLI context.
     *
     * @since 1.0.0
     */
    public function clear(): void
    {
        self::$context = [];
    }

    /**
     * Return every stored context value.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> All stored key/value pairs.
     */
    public function all(): array
    {
        return self::$context;
    }

    /**
     * Store multiple context values at once.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $context Key/value pairs to merge into the current context.
     */
    public function merge(array $context): void
    {
        self::$context = \array_merge(self::$context, $context);
    }
}
