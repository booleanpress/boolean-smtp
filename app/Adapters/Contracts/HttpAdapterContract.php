<?php
/**
 * Contract for outbound HTTP requests.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Adapters\Contracts;

/**
 * Guarantees a way to perform outbound HTTP requests and inspect their responses that does not
 * depend on the underlying host application's HTTP client.
 *
 * @since 1.0.0
 */
interface HttpAdapterContract {
    /**
     * Perform an HTTP POST request.
     *
     * @since 1.0.0
     *
     * @param  string               $url  Request URL.
     * @param  array<string, mixed> $args Request options, such as headers and body.
     * @return mixed The host-specific response representation; inspect it with isError(),
     *               responseCode() and responseBody().
     */
    public function post(string $url, array $args = []): mixed;

    /**
     * Perform an HTTP GET request.
     *
     * @since 1.0.0
     *
     * @param  string               $url  Request URL.
     * @param  array<string, mixed> $args Request options, such as headers and query parameters.
     * @return mixed The host-specific response representation; inspect it with isError(),
     *               responseCode() and responseBody().
     */
    public function get(string $url, array $args = []): mixed;

    /**
     * Perform an HTTP request using an arbitrary method.
     *
     * @since 1.0.0
     *
     * @param  string               $method HTTP method, for example "PUT" or "DELETE".
     * @param  string               $url    Request URL.
     * @param  array<string, mixed> $args   Request options, such as headers and body.
     * @return mixed The host-specific response representation; inspect it with isError(),
     *               responseCode() and responseBody().
     */
    public function request(string $method, string $url, array $args = []): mixed;

    /**
     * Determine whether a response represents an HTTP transport error.
     *
     * @since 1.0.0
     *
     * @param  mixed $response Response returned by post(), get() or request().
     * @return bool True when the request failed at the transport level.
     */
    public function isError(mixed $response): bool;

    /**
     * Extract a human-readable error message from a transport error.
     *
     * @since 1.0.0
     *
     * @param  mixed $response Response returned by post(), get() or request().
     * @return string The error message, or an empty string when the response is not an error.
     */
    public function getErrorMessage(mixed $response): string;

    /**
     * Extract the numeric HTTP status code from a response.
     *
     * @since 1.0.0
     *
     * @param  mixed $response Response returned by post(), get() or request().
     * @return int The HTTP status code, or 0 when unavailable.
     */
    public function responseCode(mixed $response): int;

    /**
     * Extract the raw HTTP response body.
     *
     * @since 1.0.0
     *
     * @param  mixed $response Response returned by post(), get() or request().
     * @return string The response body as a string.
     */
    public function responseBody(mixed $response): string;
}
