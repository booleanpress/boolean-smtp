<?php
/**
 * Contract for a request-scoped key/value store used to pass state through the mail pipeline.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to carry state through the mail pipeline without relying on a global hook
 * or filter.
 *
 * Values stored through this contract are scoped to the current request or task and must not
 * leak into a later one. This is used, for example, to carry a forced connection ID for a test
 * email or a routing override through code that would otherwise need a global callback to
 * communicate that state.
 *
 * @since 1.0.0
 */
interface ContextAdapterContract
{
    /**
     * Store a context value under a key, scoped to the current request.
     *
     * @since 1.0.0
     *
     * @param  string $key   Context key.
     * @param  mixed  $value Context value.
     */
    public function set(string $key, mixed $value): void;

    /**
     * Retrieve a stored context value.
     *
     * @since 1.0.0
     *
     * @param  string $key     Context key.
     * @param  mixed  $default Value returned when the key is not set.
     * @return mixed The stored value, or $default when the key is not set.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Determine whether a context key is set.
     *
     * @since 1.0.0
     *
     * @param  string $key Context key.
     * @return bool True when the key has a stored value.
     */
    public function has(string $key): bool;

    /**
     * Remove a single stored context value.
     *
     * @since 1.0.0
     *
     * @param  string $key Context key.
     */
    public function forget(string $key): void;

    /**
     * Remove all stored context values.
     *
     * Call this at the end of a request or task to prevent state from leaking into a later one;
     * implementations are typically invoked from a finally block.
     *
     * @since 1.0.0
     */
    public function clear(): void;

    /**
     * Return every stored context value.
     *
     * Intended for diagnostic use.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> All stored key/value pairs.
     */
    public function all(): array;

    /**
     * Store multiple context values at once.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $context Key/value pairs to merge into the current context.
     */
    public function merge(array $context): void;
}
