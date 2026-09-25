<?php
/**
 * Parses the address shapes the source plugins log: `Name <email>`, comma lists, serialized
 * `{email, name}` pairs and JSON lists.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Support;

/**
 * Every recipient or sender field of the four log tables goes through this class, so the
 * plugin's `to`, `cc`, `bcc`, `from_email` and `from_name` columns are filled the same way
 * whatever the source stored.
 *
 * @since 1.0.0
 */
final class AddressParser
{
    /**
     * Split `Name <email>` into its two parts; a bare address has no name.
     *
     * @since 1.0.0
     *
     * @param  string $value One address, with or without a display name.
     * @return array{email: string, name: string}
     */
    public static function one(string $value): array
    {
        $value = trim($value);
        if (preg_match('/^(.*?)\s*<([^<>]+)>\s*$/', $value, $m)) {
            return ['email' => trim($m[2]), 'name' => trim($m[1], " \t\"'")];
        }

        return ['email' => trim($value, " \t\"'<>"), 'name' => ''];
    }

    /**
     * Every address in a comma-separated list, names dropped.
     *
     * @since 1.0.0
     *
     * @param  string $list Comma-separated addresses, each with or without a name.
     * @return list<string>
     */
    public static function list(string $list): array
    {
        $out = [];
        foreach (self::splitList($list) as $part) {
            $email = self::one($part)['email'];
            if ($email !== '') {
                $out[] = $email;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Addresses from a value that may be a comma list, a serialized or JSON list of strings, or
     * a serialized or JSON list of `{email, name}` maps — the four shapes the sources use.
     *
     * @since 1.0.0
     *
     * @param  mixed $value Stored value.
     * @return list<string>
     */
    public static function fromStored(mixed $value): array
    {
        if (\is_string($value)) {
            $decoded = self::decode($value);
            if ($decoded === null) {
                return self::list($value);
            }
            $value = $decoded;
        }
        if (! \is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $email = trim((string) ($item['email'] ?? $item['address'] ?? ''));
                if ($email !== '') {
                    $out[] = $email;
                }
            } elseif (\is_string($item)) {
                foreach (self::list($item) as $email) {
                    $out[] = $email;
                }
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * Unserialize or JSON-decode a stored value into an array.
     *
     * @since 1.0.0
     *
     * @param  string $value Stored string.
     * @return array<mixed>|null Null when the string is neither.
     */
    public static function decode(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (str_starts_with($value, 'a:')) {
            $data = @unserialize($value, ['allowed_classes' => false]);

            return \is_array($data) ? $data : null;
        }
        if ($value[0] === '[' || $value[0] === '{') {
            $data = json_decode($value, true);

            return \is_array($data) ? $data : null;
        }

        return null;
    }

    /**
     * Split a comma list without breaking a quoted display name that contains a comma.
     *
     * @since 1.0.0
     *
     * @param  string $list Comma-separated addresses.
     * @return list<string>
     */
    private static function splitList(string $list): array
    {
        $parts = preg_split('/,(?=(?:[^"]*"[^"]*")*[^"]*$)/', $list) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $p): bool => $p !== ''));
    }
}
