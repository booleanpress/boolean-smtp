<?php
/**
 * Contract for reading the current authentication and authorization state.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to read the current user and check capabilities that does not depend on the
 * underlying host application.
 *
 * @since 1.0.0
 */
interface AuthAdapterContract {
    /**
     * Return the currently authenticated user.
     *
     * @since 1.0.0
     *
     * @return object|null The authenticated user, or null when no user is authenticated.
     */
    public function currentUser(): ?object;

    /**
     * Return the ID of the currently authenticated user.
     *
     * @since 1.0.0
     *
     * @return int The user ID, or 0 when no user is authenticated.
     */
    public function currentUserId(): int;

    /**
     * Determine whether the current user has the given capability.
     *
     * @since 1.0.0
     *
     * @param  string $capability Capability or permission to check.
     * @param  mixed  ...$args    Additional context passed to the capability check, such as an object ID.
     * @return bool True when the current user has the capability.
     */
    public function can(string $capability, mixed ...$args): bool;

    /**
     * Determine whether a user is authenticated for the current request.
     *
     * @since 1.0.0
     *
     * @return bool True when a user is logged in.
     */
    public function isLoggedIn(): bool;

    /**
     * Resolve a user by ID.
     *
     * @since 1.0.0
     *
     * @param  int $userId User ID to resolve.
     * @return object|null The user, or null when no user exists with that ID.
     */
    public function userById(int $userId): ?object;
}
