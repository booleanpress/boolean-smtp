<?php
/**
 * WordPress implementation of the authentication adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\AuthAdapterContract;

/**
 * Reads authentication state from WordPress user functions.
 *
 * Every method guards against the underlying WordPress function being unavailable, such as when
 * running under a test harness without WordPress loaded, and falls back to an unauthenticated
 * result in that case.
 *
 * @since 1.0.0
 */
final class AuthAdapter implements AuthAdapterContract {
    /**
     * Return the currently authenticated WordPress user.
     *
     * @since 1.0.0
     *
     * @return object|null The current WP_User, or null when no user is authenticated.
     */
    public function currentUser(): ?object {
        if (!\function_exists('wp_get_current_user')) {
            return null;
        }

        $user = \wp_get_current_user();
        if (!\is_object($user) || !isset($user->ID) || (int) $user->ID <= 0) {
            return null;
        }

        return $user;
    }

    /**
     * Return the ID of the currently authenticated WordPress user.
     *
     * @since 1.0.0
     *
     * @return int The user ID, or 0 when no user is authenticated.
     */
    public function currentUserId(): int {
        if (\function_exists('get_current_user_id')) {
            return (int) \get_current_user_id();
        }

        $user = $this->currentUser();
        return $user && isset($user->ID) ? (int) $user->ID : 0;
    }

    /**
     * Determine whether the current user has the given WordPress capability.
     *
     * @since 1.0.0
     *
     * @param  string $capability Capability or permission to check.
     * @param  mixed  ...$args    Additional context passed to the capability check, such as an object ID.
     * @return bool True when the current user has the capability.
     */
    public function can(string $capability, mixed ...$args): bool {
        if (!\function_exists('current_user_can')) {
            return false;
        }

        return (bool) \current_user_can($capability, ...$args);
    }

    /**
     * Determine whether a WordPress user is logged in for the current request.
     *
     * @since 1.0.0
     *
     * @return bool True when a user is logged in.
     */
    public function isLoggedIn(): bool {
        return \function_exists('is_user_logged_in') ? (bool) \is_user_logged_in() : false;
    }

    /**
     * Resolve a WordPress user by ID.
     *
     * @since 1.0.0
     *
     * @param  int $userId User ID to resolve.
     * @return object|null The WP_User, or null when no user exists with that ID.
     */
    public function userById(int $userId): ?object {
        if (!\function_exists('get_user_by')) {
            return null;
        }

        $user = \get_user_by('id', $userId);
        return \is_object($user) ? $user : null;
    }
}
