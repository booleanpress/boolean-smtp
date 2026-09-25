<?php
/**
 * Contract for registering and firing hooks.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to register listeners and fire hooks that does not depend on the underlying
 * host application's event system.
 *
 * @since 1.0.0
 */
interface HookAdapterContract {
    /**
     * Register a callback to run when a hook is dispatched or filtered.
     *
     * @since 1.0.0
     *
     * @param  string   $hookName     Hook identifier, for example "boolean_smtp_email_sent".
     * @param  callable $callback     Handler to invoke.
     * @param  int      $priority     Execution priority; lower values run earlier.
     * @param  int      $acceptedArgs Number of arguments passed to the callback.
     */
    public function listen(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void;

    /**
     * Fire a hook, invoking every registered listener without expecting a return value.
     *
     * @since 1.0.0
     *
     * @param  string $hookName Hook identifier.
     * @param  mixed  ...$args  Arguments passed to each listener.
     */
    public function dispatch(string $hookName, mixed ...$args): void;

    /**
     * Fire a hook that allows listeners to modify and return a value.
     *
     * @since 1.0.0
     *
     * @param  string $hookName Hook identifier.
     * @param  mixed  $value    Initial value, passed to and potentially modified by each listener.
     * @param  mixed  ...$args  Additional context passed to each listener.
     * @return mixed The value after passing through every registered listener.
     */
    public function filter(string $hookName, mixed $value, mixed ...$args): mixed;

    /**
     * Remove a previously registered listener from a hook.
     *
     * @since 1.0.0
     *
     * @param  string   $hookName Hook identifier.
     * @param  callable $callback Handler to remove.
     * @param  int      $priority Priority the handler was registered with.
     */
    public function removeListener(string $hookName, callable $callback, int $priority = 10): void;
}
