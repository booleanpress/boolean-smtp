<?php
/**
 * How a stored secret is shown in the admin screens, and how a shown secret is recognised.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

/**
 * A saved secret leaves the server only as asterisks followed by its last four characters. When
 * the admin screen sends that value back unchanged, it is recognised here, so the saved secret is
 * kept instead of being replaced by the asterisks.
 *
 * @since 1.0.0
 */
final class SecretMask
{
    /**
     * A shown secret: at least four asterisks, then up to eight visible characters.
     *
     * @since 1.0.0
     * @var string
     */
    public const PATTERN = '/^\*{4,}\S{0,8}$/';

    /**
     * Hide a secret, keeping its last four characters. A secret of four characters or fewer is
     * hidden completely; an empty value stays empty.
     *
     * @since 1.0.0
     *
     * @param  string $value The secret.
     * @return string
     */
    public static function mask(string $value): string
    {
        $length = strlen($value);
        if ($length === 0) {
            return '';
        }
        if ($length <= 4) {
            return str_repeat('*', 4);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }

    /**
     * Whether a value is a secret as {@see self::mask()} shows it, rather than a new secret.
     *
     * @since 1.0.0
     *
     * @param  string $value The value the admin screen sent.
     * @return bool
     */
    public static function isMasked(string $value): bool
    {
        return $value !== '' && preg_match(self::PATTERN, $value) === 1;
    }
}
