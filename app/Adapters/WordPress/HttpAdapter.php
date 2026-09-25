<?php
/**
 * WordPress implementation of the HTTP adapter contract.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\WordPress;

use BooleanSmtp\Adapters\Contracts\HttpAdapterContract;

/**
 * Performs outbound HTTP requests using the WordPress HTTP API.
 *
 * @since 1.0.0
 */
final class HttpAdapter implements HttpAdapterContract {
    /**
     * Send an HTTP POST request.
     *
     * @since 1.0.0
     *
     * @param string $url Request URL.
     * @param array<string, mixed> $args Request options, such as headers and body.
     * @return mixed The response from wp_remote_post(), or a WP_Error when the WordPress HTTP API
     *               is unavailable.
     */
    public function post(string $url, array $args = []): mixed {
        if (\function_exists('wp_remote_post')) {
            return \wp_remote_post($url, $args);
        }

        return new \WP_Error('booleansmtp_http_unavailable', 'wp_remote_post is not available.');
    }

    /**
     * Send an HTTP GET request.
     *
     * @since 1.0.0
     *
     * @param string $url Request URL.
     * @param array<string, mixed> $args Request options, such as headers and query parameters.
     * @return mixed The response from wp_remote_get(), or a WP_Error when the WordPress HTTP API
     *               is unavailable.
     */
    public function get(string $url, array $args = []): mixed {
        if (\function_exists('wp_remote_get')) {
            return \wp_remote_get($url, $args);
        }

        return new \WP_Error('booleansmtp_http_unavailable', 'wp_remote_get is not available.');
    }

    /**
     * Send an HTTP request using an arbitrary method.
     *
     * @since 1.0.0
     *
     * @param string $method HTTP method, for example "PUT" or "DELETE".
     * @param string $url Request URL.
     * @param array<string, mixed> $args Request options, such as headers and body.
     * @return mixed The response from wp_remote_request(), or a WP_Error when the WordPress HTTP
     *               API is unavailable.
     */
    public function request(string $method, string $url, array $args = []): mixed {
        if (\function_exists('wp_remote_request')) {
            $args['method'] = strtoupper($method);
            return \wp_remote_request($url, $args);
        }

        return new \WP_Error('booleansmtp_http_unavailable', 'wp_remote_request is not available.');
    }

    /**
     * Determine whether a response represents an HTTP transport error.
     *
     * @since 1.0.0
     *
     * @param mixed $response Response returned by post(), get() or request().
     * @return bool True when the response is a WP_Error.
     */
    public function isError(mixed $response): bool {
        return \function_exists('is_wp_error') ? \is_wp_error($response) : false;
    }

    /**
     * Extract a human-readable error message from a transport error.
     *
     * @since 1.0.0
     *
     * @param mixed $response Response returned by post(), get() or request().
     * @return string The error message, or an empty string when the response is not an error.
     */
    public function getErrorMessage(mixed $response): string {
        if ($this->isError($response) && \is_object($response) && \method_exists($response, 'get_error_message')) {
            return (string) $response->get_error_message();
        }

        return '';
    }

    /**
     * Extract the numeric HTTP status code from a response.
     *
     * @since 1.0.0
     *
     * @param mixed $response Response returned by post(), get() or request().
     * @return int The HTTP status code, or 0 when the response is an error or the code is
     *             unavailable.
     */
    public function responseCode(mixed $response): int {
        if ($this->isError($response)) {
            return 0;
        }

        if (!\function_exists('wp_remote_retrieve_response_code')) {
            return 0;
        }

        return (int) \wp_remote_retrieve_response_code($response);
    }

    /**
     * Extract the raw HTTP response body.
     *
     * @since 1.0.0
     *
     * @param mixed $response Response returned by post(), get() or request().
     * @return string The response body, or an empty string when the response is an error or the
     *                body is unavailable.
     */
    public function responseBody(mixed $response): string {
        if ($this->isError($response)) {
            return '';
        }

        if (!\function_exists('wp_remote_retrieve_body')) {
            return '';
        }

        return (string) \wp_remote_retrieve_body($response);
    }
}
