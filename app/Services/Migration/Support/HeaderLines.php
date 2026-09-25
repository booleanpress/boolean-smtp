<?php
/**
 * Turns the header blobs the source plugins log into the header map the plugin's own rows use.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Support;

/**
 * The sources store headers as a serialized or JSON list of raw `Name: value` lines, as one
 * newline-separated string, or (FluentSMTP) as a keyed array. All of them become
 * `['Name' => 'value']`; the recipient headers are pulled out separately by the mapper.
 *
 * @since 1.0.0
 */
final class HeaderLines
{
    /**
     * Header lines from a stored value of any of the three shapes.
     *
     * @since 1.0.0
     *
     * @param  mixed $stored Stored value.
     * @return list<string> Raw `Name: value` lines.
     */
    public static function lines(mixed $stored): array
    {
        if (\is_string($stored)) {
            $decoded = AddressParser::decode($stored);
            if ($decoded === null) {
                return array_values(array_filter(array_map('trim', preg_split('/\r\n|\n|\r/', $stored) ?: []), static fn (string $l): bool => $l !== ''));
            }
            $stored = $decoded;
        }
        if (! \is_array($stored)) {
            return [];
        }
        $lines = [];
        foreach ($stored as $key => $value) {
            if (\is_string($value) && \is_int($key)) {
                foreach (preg_split('/\r\n|\n|\r/', $value) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $lines[] = trim($line);
                    }
                }
            } elseif (\is_string($key) && \is_scalar($value) && trim((string) $value) !== '') {
                $lines[] = $key . ': ' . trim((string) $value);
            }
        }

        return $lines;
    }

    /**
     * A header map from raw lines; a repeated header keeps the last value, except the
     * recipient headers, which are joined.
     *
     * @since 1.0.0
     *
     * @param  list<string> $lines Raw `Name: value` lines.
     * @return array<string, string>
     */
    public static function toMap(array $lines): array
    {
        $map = [];
        foreach ($lines as $line) {
            $pos = strpos($line, ':');
            if ($pos === false || $pos === 0) {
                continue;
            }
            $name  = self::canonicalName(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            if ($value === '') {
                continue;
            }
            if (isset($map[$name]) && \in_array($name, ['To', 'Cc', 'Bcc', 'Reply-To'], true)) {
                $map[$name] .= ', ' . $value;
            } else {
                $map[$name] = $value;
            }
        }

        return $map;
    }

    /**
     * A header name in canonical case: `content-type` → `Content-Type`, `x-mailer` → `X-Mailer`,
     * `mime-version` → `MIME-Version`.
     *
     * @since 1.0.0
     *
     * @param  string $name Header name in any case.
     * @return string
     */
    public static function canonicalName(string $name): string
    {
        $name = trim($name);
        $special = ['message-id' => 'Message-ID', 'mime-version' => 'MIME-Version', 'dkim-signature' => 'DKIM-Signature'];
        if (isset($special[strtolower($name)])) {
            return $special[strtolower($name)];
        }

        return implode('-', array_map('ucfirst', explode('-', strtolower($name))));
    }
}
