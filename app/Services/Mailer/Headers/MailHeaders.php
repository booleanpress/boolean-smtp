<?php

/**
 * Ordered, case-insensitive header bag used to build outgoing message headers.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer\Headers;

use BooleanSmtp\Contracts\MailHeadersContract;

/**
 * Case-insensitive header names; preserves display casing on first set.
 *
 * @since 1.0.0
 */
final class MailHeaders implements MailHeadersContract
{
    /**
     * Header entries keyed by lowercase name, each retaining its original display casing.
     *
     * @since 1.0.0
     * @var array<string, array{name: string, value: string}>
     */
    private array $entries = [];

    /**
     * Get a header value by name.
     *
     * @since 1.0.0
     *
     * @param  string $name Header name, matched case-insensitively.
     * @return string|null Header value, or null when the header is not set.
     */
    public function get(string $name): ?string
    {
        $k = strtolower($name);

        return $this->entries[$k]['value'] ?? null;
    }

    /**
     * Set a header, replacing any existing value for the same name.
     *
     * @since 1.0.0
     *
     * @param  string $name  Header name.
     * @param  string $value Header value.
     * @return void
     */
    public function set(string $name, string $value): void
    {
        $k = strtolower($name);
        $this->entries[$k] = ['name' => $name, 'value' => $value];
    }

    /**
     * Add a header value, appending to an existing value for the same name as a comma-separated list.
     *
     * @since 1.0.0
     *
     * @param  string $name  Header name.
     * @param  string $value Header value to add.
     * @return void
     */
    public function add(string $name, string $value): void
    {
        $k = strtolower($name);
        if (isset($this->entries[$k])) {
            $this->entries[$k]['value'] .= ', ' . $value;
        } else {
            $this->entries[$k] = ['name' => $name, 'value' => $value];
        }
    }

    /**
     * Remove a header by name.
     *
     * @since 1.0.0
     *
     * @param  string $name Header name, matched case-insensitively.
     * @return void
     */
    public function remove(string $name): void
    {
        unset($this->entries[strtolower($name)]);
    }

    /**
     * Get all headers as a flat map of lowercase name to value.
     *
     * @since 1.0.0
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        $out = [];
        foreach ($this->entries as $row) {
            $out[strtolower($row['name'])] = $row['value'];
        }

        return $out;
    }

    /**
     * Render the headers as "Name: value" lines in insertion order.
     *
     * @since 1.0.0
     *
     * @return array<int, string>
     */
    public function toHeaderLines(): array
    {
        $lines = [];
        foreach ($this->entries as $row) {
            $lines[] = $row['name'] . ': ' . $row['value'];
        }

        return $lines;
    }

    /**
     * Build a header bag from the headers argument accepted by wp_mail().
     *
     * @since 1.0.0
     *
     * @param  array|string $headers wp_mail-style headers (string with newlines or array of lines)
     * @return self
     */
    public static function fromWpMailHeaders(array|string $headers): self
    {
        $bag = new self();
        if ($headers === '' || $headers === []) {
            return $bag;
        }

        if (\is_string($headers)) {
            $lines = preg_split('/\r\n|\n|\r/', $headers) ?: [];
        } else {
            $lines = $headers;
        }

        foreach ($lines as $line) {
            $line = trim((string) $line);
            if ($line === '' || ! str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $name  = trim($name);
            $value = trim($value);
            if ($name !== '') {
                $bag->set($name, $value);
            }
        }

        return $bag;
    }

    /**
     * Merge bag lines into a wp_mail $headers string (newline-separated).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function toWpMailHeaderString(): string
    {
        return implode("\n", $this->toHeaderLines());
    }

}
