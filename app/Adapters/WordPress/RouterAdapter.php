<?php
/**
 * WordPress implementation of the router adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\RouterAdapterContract;

/**
 * Reads request context and builds URLs using WordPress routing functions.
 *
 * @since 1.0.0
 */
final class RouterAdapter implements RouterAdapterContract {
    /**
     * Determine whether the current request is in the WordPress admin.
     *
     * @since 1.0.0
     *
     * @return bool True when the current request is an admin request.
     */
    public function isAdmin(): bool {
        return \function_exists('is_admin') ? (bool) \is_admin() : false;
    }

    /**
     * Build an admin URL for a path.
     *
     * @since 1.0.0
     *
     * @param string $path Path relative to the admin root.
     * @return string The absolute admin URL, or $path unchanged when WordPress is unavailable.
     */
    public function adminUrl(string $path = ''): string {
        return \function_exists('admin_url') ? (string) \admin_url($path) : $path;
    }

    /**
     * Build a REST API URL for a path.
     *
     * @since 1.0.0
     *
     * @param string $path Path relative to the REST API root.
     * @return string The absolute REST API URL, or $path unchanged when WordPress is unavailable.
     */
    public function restUrl(string $path = ''): string {
        if (!\function_exists('get_rest_url')) {
            return $path;
        }

        return (string) \get_rest_url(null, $path);
    }

    /**
     * Determine whether the current request targets the REST API.
     *
     * Checks the REST_REQUEST constant first, then falls back to matching "/wp-json/" in the
     * request URI for cases where the constant has not been set yet.
     *
     * @since 1.0.0
     *
     * @return bool True when the current request targets the REST API.
     */
    public function isRestRequest(): bool {
        if (\defined('REST_REQUEST') && \REST_REQUEST) {
            return true;
        }

        if (isset($_SERVER['REQUEST_URI']) && \is_string($_SERVER['REQUEST_URI'])) {
            return str_contains(\sanitize_text_field(\wp_unslash($_SERVER['REQUEST_URI'])), '/wp-json/');
        }

        return false;
    }
}
