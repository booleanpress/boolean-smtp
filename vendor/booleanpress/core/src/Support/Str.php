<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Support;

/**
 * String Helper
 *
 * Utility methods for working with strings.
 */
class Str
{
    /**
     * The cache of snake-cased words.
     *
     * @var array<string, string>
     */
    protected static array $snakeCache = [];

    /**
     * The cache of camel-cased words.
     *
     * @var array<string, string>
     */
    protected static array $camelCache = [];

    /**
     * The cache of studly-cased words.
     *
     * @var array<string, string>
     */
    protected static array $studlyCache = [];

    /**
     * Convert a value to camelCase.
     */
    public static function camel(string $value): string
    {
        if (isset(static::$camelCache[$value])) {
            return static::$camelCache[$value];
        }

        return static::$camelCache[$value] = lcfirst(static::studly($value));
    }

    /**
     * Convert a value to StudlyCase.
     */
    public static function studly(string $value): string
    {
        if (isset(static::$studlyCache[$value])) {
            return static::$studlyCache[$value];
        }

        $words = preg_split('/[^a-zA-Z0-9]+/', $value);
        $studly = '';

        foreach ($words as $word) {
            $studly .= ucfirst($word);
        }

        return static::$studlyCache[$value] = $studly;
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        $key = $value . $delimiter;

        if (isset(static::$snakeCache[$key])) {
            return static::$snakeCache[$key];
        }

        if (!ctype_lower($value)) {
            $value = preg_replace('/([A-Z])/', $delimiter . '$1', $value);
            $value = preg_replace('/[\s-]+/', $delimiter, $value);
            $value = ltrim($value, $delimiter);
        }

        return static::$snakeCache[$key] = strtolower($value);
    }

    /**
     * Convert a string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return static::snake($value, '-');
    }

    /**
     * Convert the given string to title case.
     */
    public static function title(string $value): string
    {
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * Convert the first character to upper case.
     */
    public static function ucfirst(string $value): string
    {
        return static::upper(static::substr($value, 0, 1)) . static::substr($value, 1);
    }

    /**
     * Convert the first character to lower case.
     */
    public static function lcfirst(string $value): string
    {
        return static::lower(static::substr($value, 0, 1)) . static::substr($value, 1);
    }

    /**
     * Convert a string to upper case.
     */
    public static function upper(string $value): string
    {
        return mb_strtoupper($value, 'UTF-8');
    }

    /**
     * Convert a string to lower case.
     */
    public static function lower(string $value): string
    {
        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Return the length of the given string.
     */
    public static function length(string $value): int
    {
        return mb_strlen($value, 'UTF-8');
    }

    /**
     * Return the portion of string specified by the offset and length.
     */
    public static function substr(string $value, int $start, ?int $length = null): string
    {
        return mb_substr($value, $start, $length, 'UTF-8');
    }

    /**
     * Determine if a given string contains a given substring.
     */
    public static function contains(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string contains all array values.
     *
     * @param array<string> $needles
     */
    public static function containsAll(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine if a given string starts with a given substring.
     */
    public static function startsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_starts_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string ends with a given substring.
     */
    public static function endsWith(string $haystack, string|array $needles): bool
    {
        foreach ((array) $needles as $needle) {
            if ($needle !== '' && str_ends_with($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Cap a string with a single instance of a given value.
     */
    public static function finish(string $value, string $cap): string
    {
        $quoted = preg_quote($cap, '/');
        return preg_replace('/(?:' . $quoted . ')+$/u', '', $value) . $cap;
    }

    /**
     * Begin a string with a single instance of a given value.
     */
    public static function start(string $value, string $prefix): string
    {
        $quoted = preg_quote($prefix, '/');
        return $prefix . preg_replace('/^(?:' . $quoted . ')+/u', '', $value);
    }

    /**
     * Get the portion of a string before the first occurrence of a given value.
     */
    public static function before(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        if ($pos === false) {
            return $subject;
        }

        return static::substr($subject, 0, $pos);
    }

    /**
     * Get the portion of a string before the last occurrence of a given value.
     */
    public static function beforeLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = mb_strrpos($subject, $search, 0, 'UTF-8');

        if ($pos === false) {
            return $subject;
        }

        return static::substr($subject, 0, $pos);
    }

    /**
     * Return the remainder of a string after the first occurrence of a given value.
     */
    public static function after(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        if ($pos === false) {
            return $subject;
        }

        return static::substr($subject, $pos + static::length($search));
    }

    /**
     * Return the remainder of a string after the last occurrence of a given value.
     */
    public static function afterLast(string $subject, string $search): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = mb_strrpos($subject, $search, 0, 'UTF-8');

        if ($pos === false) {
            return $subject;
        }

        return static::substr($subject, $pos + static::length($search));
    }

    /**
     * Get the portion of a string between two given values.
     */
    public static function between(string $subject, string $from, string $to): string
    {
        if ($from === '' || $to === '') {
            return $subject;
        }

        return static::beforeLast(static::after($subject, $from), $to);
    }

    /**
     * Replace the first occurrence of a given value in the string.
     */
    public static function replaceFirst(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strpos($subject, $search);

        if ($pos !== false) {
            return substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    /**
     * Replace the last occurrence of a given value in the string.
     */
    public static function replaceLast(string $search, string $replace, string $subject): string
    {
        if ($search === '') {
            return $subject;
        }

        $pos = strrpos($subject, $search);

        if ($pos !== false) {
            return substr_replace($subject, $replace, $pos, strlen($search));
        }

        return $subject;
    }

    /**
     * Limit the number of characters in a string.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (static::length($value) <= $limit) {
            return $value;
        }

        return rtrim(static::substr($value, 0, $limit)) . $end;
    }

    /**
     * Limit the number of words in a string.
     */
    public static function words(string $value, int $words = 100, string $end = '...'): string
    {
        preg_match('/^\s*+(?:\S++\s*+){1,' . $words . '}/u', $value, $matches);

        if (!isset($matches[0]) || static::length($value) === static::length($matches[0])) {
            return $value;
        }

        return rtrim($matches[0]) . $end;
    }

    /**
     * Generate a URL friendly "slug" from a given string.
     */
    public static function slug(string $title, string $separator = '-'): string
    {
        // Convert to lowercase
        $title = static::lower($title);

        // Replace @ with 'at'
        $title = str_replace('@', $separator . 'at' . $separator, $title);

        // Remove all characters that are not alphanumeric, spaces, or dashes
        $title = preg_replace('![^' . preg_quote($separator) . '\pL\pN\s]+!u', '', $title);

        // Replace all separator characters and whitespace
        $title = preg_replace('![' . preg_quote($separator) . '\s]+!u', $separator, $title);

        return trim($title, $separator);
    }

    /**
     * Generate a random string.
     */
    public static function random(int $length = 16): string
    {
        $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $charactersLength = strlen($characters);
        $randomString = '';

        for ($i = 0; $i < $length; $i++) {
            $randomString .= $characters[random_int(0, $charactersLength - 1)];
        }

        return $randomString;
    }

    /**
     * Generate a UUID (version 4).
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr(ord($bytes[6]) & 0x0f | 0x40); // version 4
        $bytes[8] = chr(ord($bytes[8]) & 0x3f | 0x80); // variant RFC4122

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Determine if a given string matches a given pattern.
     */
    public static function is(string|array $pattern, string $value): bool
    {
        $patterns = (array) $pattern;

        foreach ($patterns as $pattern) {
            if ($pattern === $value) {
                return true;
            }

            $pattern = preg_quote($pattern, '#');
            $pattern = str_replace('\*', '.*', $pattern);

            if (preg_match('#^' . $pattern . '\z#u', $value) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine if a given string is a valid UUID.
     */
    public static function isUuid(string $value): bool
    {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1;
    }

    /**
     * Determine if a given string is a valid JSON.
     */
    public static function isJson(string $value): bool
    {
        json_decode($value);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Mask a portion of a string with a repeated character.
     */
    public static function mask(string $string, string $character, int $index, ?int $length = null): string
    {
        if ($character === '') {
            return $string;
        }

        $start = static::substr($string, 0, $index);
        $end = $length === null ? '' : static::substr($string, $index + $length);
        $masked = str_repeat(static::substr($character, 0, 1), $length ?? static::length($string) - $index);

        return $start . $masked . $end;
    }

    /**
     * Pad both sides of a string with another.
     */
    public static function padBoth(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_BOTH);
    }

    /**
     * Pad the left side of a string with another.
     */
    public static function padLeft(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_LEFT);
    }

    /**
     * Pad the right side of a string with another.
     */
    public static function padRight(string $value, int $length, string $pad = ' '): string
    {
        return str_pad($value, $length, $pad, STR_PAD_RIGHT);
    }

    /**
     * Repeat the given string.
     */
    public static function repeat(string $string, int $times): string
    {
        return str_repeat($string, $times);
    }

    /**
     * Reverse the given string.
     */
    public static function reverse(string $value): string
    {
        return implode('', array_reverse(mb_str_split($value)));
    }

    /**
     * Remove any occurrence of the given string in the subject.
     */
    public static function remove(string|array $search, string $subject, bool $caseSensitive = true): string
    {
        return $caseSensitive
            ? str_replace($search, '', $subject)
            : str_ireplace($search, '', $subject);
    }

    /**
     * Generate a "headline" from a string.
     */
    public static function headline(string $value): string
    {
        $parts = explode(' ', static::snake($value, ' '));
        $parts = array_filter($parts);

        return implode(' ', array_map([static::class, 'ucfirst'], $parts));
    }

    /**
     * Wrap the string with the given strings.
     */
    public static function wrap(string $value, string $before, ?string $after = null): string
    {
        return $before . $value . ($after ?? $before);
    }

    /**
     * Pluralize a word based on count (basic English rules).
     */
    public static function plural(string $value, int $count = 2): string
    {
        if ($count === 1) {
            return $value;
        }

        // Very basic pluralization - extend as needed
        if (static::endsWith($value, ['s', 'x', 'z', 'ch', 'sh'])) {
            return $value . 'es';
        }

        if (static::endsWith($value, 'y') && !static::endsWith($value, ['ay', 'ey', 'iy', 'oy', 'uy'])) {
            return static::substr($value, 0, -1) . 'ies';
        }

        return $value . 's';
    }

    /**
     * Singularize a word (basic English rules).
     */
    public static function singular(string $value): string
    {
        // Very basic singularization - extend as needed
        if (static::endsWith($value, 'ies')) {
            return static::substr($value, 0, -3) . 'y';
        }

        if (static::endsWith($value, 'es') && static::endsWith($value, ['ses', 'xes', 'zes', 'ches', 'shes'])) {
            return static::substr($value, 0, -2);
        }

        if (static::endsWith($value, 's') && !static::endsWith($value, 'ss')) {
            return static::substr($value, 0, -1);
        }

        return $value;
    }

    /**
     * Clear the caches.
     */
    public static function clearCache(): void
    {
        static::$snakeCache = [];
        static::$camelCache = [];
        static::$studlyCache = [];
    }
}
