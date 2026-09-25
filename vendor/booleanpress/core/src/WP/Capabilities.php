<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\WP;

/**
 * Capabilities Wrapper
 *
 * Provides a clean interface for WordPress user capabilities and roles.
 */
class Capabilities
{
    /**
     * Check if the current user has a capability.
     */
    public function can(string $capability, ...$args): bool
    {
        return current_user_can($capability, ...$args);
    }

    /**
     * Check if a specific user has a capability.
     */
    public function userCan(int|\WP_User $user, string $capability, ...$args): bool
    {
        return user_can($user, $capability, ...$args);
    }

    /**
     * Get the role of the current user.
     */
    public function getRoles(): array
    {
        $user = wp_get_current_user();
        return (array) $user->roles;
    }

    /**
     * Add a custom role.
     */
    public function addRole(string $role, string $displayName, array $capabilities = []): ?\WP_Role
    {
        return add_role($role, $displayName, $capabilities);
    }

    /**
     * Remove a custom role.
     */
    public function removeRole(string $role): void
    {
        remove_role($role);
    }
}
