<?php
/**
 * Contract for routing and URL helpers.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to inspect the current request context and build URLs that does not depend
 * on the underlying host application's routing layer.
 *
 * @since 1.0.0
 */
interface RouterAdapterContract {
    /**
     * Determine whether the current request is in an administrative context.
     *
     * @since 1.0.0
     *
     * @return bool True when the current request is an admin request.
     */
    public function isAdmin(): bool;

    /**
     * Build an administrative URL for a path.
     *
     * @since 1.0.0
     *
     * @param  string $path Path relative to the admin root.
     * @return string The absolute admin URL.
     */
    public function adminUrl(string $path = ''): string;

    /**
     * Build a REST API URL for a path.
     *
     * @since 1.0.0
     *
     * @param  string $path Path relative to the REST API root.
     * @return string The absolute REST API URL.
     */
    public function restUrl(string $path = ''): string;

    /**
     * Determine whether the current request is a REST API request.
     *
     * @since 1.0.0
     *
     * @return bool True when the current request targets the REST API.
     */
    public function isRestRequest(): bool;
}
