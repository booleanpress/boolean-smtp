<?php
/**
 * The base64 flavours two source plugins call "encryption".
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Decoders;

/**
 * Post SMTP base64-encodes a password once (its fallback SMTP password sometimes twice, which
 * its own reader undoes speculatively); SureMail base64-encodes and strips the `=` padding.
 * None of these needs a key.
 *
 * @since 1.0.0
 */
final class Base64Value
{
    /**
     * Decode one layer of base64.
     *
     * @since 1.0.0
     *
     * @param  string $stored Stored value.
     * @return string|null The decoded string, or null when the value is not base64.
     */
    public static function once(string $stored): ?string
    {
        if ($stored === '') {
            return null;
        }
        $decoded = base64_decode($stored, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Decode base64 repeatedly while the result still looks like base64 — Post SMTP's own
     * rule for its fallback password — up to `$maxRounds` layers.
     *
     * @since 1.0.0
     *
     * @param  string $stored    Stored value.
     * @param  int    $maxRounds Layers to peel at most.
     * @return string The innermost plaintext; the input when it was not base64 at all.
     */
    public static function untilPlain(string $stored, int $maxRounds = 2): string
    {
        $value = $stored;
        for ($i = 0; $i < $maxRounds; $i++) {
            if (! self::looksBase64($value)) {
                break;
            }
            $decoded = base64_decode($value, true);
            if ($decoded === false || $decoded === '' || ! self::isPrintable($decoded)) {
                break;
            }
            $value = $decoded;
        }

        return $value;
    }

    /**
     * Decode base64 whose `=` padding was stripped.
     *
     * @since 1.0.0
     *
     * @param  string $stored Stored value without padding.
     * @return string|null The decoded string, or null when the value is not base64.
     */
    public static function unpadded(string $stored): ?string
    {
        $stored = rtrim($stored, '=');
        if ($stored === '') {
            return null;
        }
        $padded  = $stored . str_repeat('=', (4 - strlen($stored) % 4) % 4);
        $decoded = base64_decode($padded, true);

        return $decoded === false ? null : $decoded;
    }

    /**
     * Whether a string is well-formed base64 of a multiple-of-four length.
     *
     * @since 1.0.0
     *
     * @param  string $value String to test.
     * @return bool
     */
    public static function looksBase64(string $value): bool
    {
        return $value !== '' && strlen($value) % 4 === 0 && (bool) preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value);
    }

    /**
     * Whether a decoded string is printable text rather than binary — the sign that a base64
     * layer was really there.
     *
     * @since 1.0.0
     *
     * @param  string $value Decoded string.
     * @return bool
     */
    private static function isPrintable(string $value): bool
    {
        return ! (bool) preg_match('/[^\x20-\x7E\t\r\n\x80-\xFF]/', $value) && mb_check_encoding($value, 'UTF-8');
    }
}
