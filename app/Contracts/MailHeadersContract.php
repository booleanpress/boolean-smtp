<?php
/**
 * Contract for a mutable map of custom RFC 5322 mail headers.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a mutable RFC 5322-style headers map for custom headers; the From and To headers
 * live on PHPMailer instead.
 *
 * @since 1.0.0
 */
interface MailHeadersContract
{
    /**
     * Return the value of a header.
     *
     * @since 1.0.0
     *
     * @param  string $name Header name, case-insensitive.
     * @return string|null The header value, or null when the header is not set.
     */
    public function get(string $name): ?string;

    /**
     * Set a header, replacing any existing value.
     *
     * @since 1.0.0
     *
     * @param  string $name  Header name, case-insensitive.
     * @param  string $value Header value.
     */
    public function set(string $name, string $value): void;

    /**
     * Add a header value without replacing an existing one.
     *
     * @since 1.0.0
     *
     * @param  string $name  Header name, case-insensitive.
     * @param  string $value Header value.
     */
    public function add(string $name, string $value): void;

    /**
     * Remove a header.
     *
     * @since 1.0.0
     *
     * @param  string $name Header name, case-insensitive.
     */
    public function remove(string $name): void;

    /**
     * Return every custom header.
     *
     * @since 1.0.0
     *
     * @return array<string, string> Header values keyed by lowercase header name.
     */
    public function all(): array;

    /**
     * Format the headers as lines suitable for wp_mail()'s $headers argument.
     *
     * @since 1.0.0
     *
     * @return array<int, string> Lines in "Name: value" format.
     */
    public function toHeaderLines(): array;
}
