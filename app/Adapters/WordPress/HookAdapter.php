<?php
/**
 * WordPress implementation of the hook adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\HookAdapterContract;

/**
 * Registers and fires hooks using WordPress's action and filter system.
 *
 * Wrapping add_action()/do_action()/apply_filters() behind this contract lets hooks be mocked in
 * tests and keeps calling code decoupled from the WordPress hook API.
 *
 * @since 1.0.0
 */
final class HookAdapter implements HookAdapterContract {
    /**
     * Register a hook listener (action handler).
     *
     * @since 1.0.0
     *
     * @param string $hookName Hook identifier
     * @param callable $callback Handler function
     * @param int $priority Execution priority (default 10)
     * @param int $acceptedArgs Number of arguments callback accepts (default 1)
     */
    public function listen(string $hookName, callable $callback, int $priority = 10, int $acceptedArgs = 1): void {
        if (\function_exists('add_action')) {
            \add_action($hookName, $callback, $priority, $acceptedArgs);
        }
    }

    /**
     * Dispatch a hook (trigger action).
     *
     * @since 1.0.0
     *
     * @param string $hookName Hook identifier
     * @param mixed ...$args Arguments for listeners
     */
    public function dispatch(string $hookName, mixed ...$args): void {
        if (\function_exists('do_action')) {
            /** Dispatches the named WordPress action with the given arguments. */
            \do_action($hookName, ...$args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- adapter: callers pass their own boolean_smtp_ hook names.
        }
    }

    /**
     * Fire a hook with value modification (apply filter).
     *
     * @since 1.0.0
     *
     * @param string $hookName Hook identifier
     * @param mixed $value Initial value
     * @param mixed ...$args Additional arguments
     * @return mixed The value after passing through every registered listener, or the unmodified
     *               $value when the underlying filter function is unavailable.
     */
    public function filter(string $hookName, mixed $value, mixed ...$args): mixed {
        if (\function_exists('apply_filters')) {
            /** Applies the named WordPress filter to the given value. */
            return \apply_filters($hookName, $value, ...$args); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.DynamicHooknameFound -- adapter: callers pass their own boolean_smtp_ hook names.
        }
        return $value;
    }

    /**
     * Remove a listener.
     *
     * @since 1.0.0
     *
     * @param string $hookName Hook identifier
     * @param callable $callback Handler to remove
     * @param int $priority Priority the handler was registered with
     */
    public function removeListener(string $hookName, callable $callback, int $priority = 10): void {
        if (\function_exists('remove_action')) {
            \remove_action($hookName, $callback, $priority);
        }
    }
}
