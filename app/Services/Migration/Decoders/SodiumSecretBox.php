<?php
/**
 * Decodes the SMTP password WP Mail SMTP and Easy WP SMTP seal with a libsodium secret box.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Decoders;

use BooleanSmtp\Services\Migration\SourceContext;

/**
 * Both plugins store `base64(nonce ‖ secretbox(password))` with a 32-byte key kept, base64
 * encoded, in an option of their own (`wp_mail_smtp_mail_key`, `easy_wp_smtp_mail_key`) or in a
 * constant. Their own decrypt returns the *input unchanged* on any failure, so a stored value
 * that still looks like a sealed box after "decryption" is an unrecoverable password, never
 * the password itself — {@see looksSealed()} is that check.
 *
 * @since 1.0.0
 */
final class SodiumSecretBox
{
    /**
     * @since 1.0.0
     *
     * @param string|null $key The 32-byte secret key, or null when the site has none.
     */
    public function __construct(private readonly ?string $key) {}

    /**
     * The box for a site, from the plugin's constant or its key option.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context      Site access.
     * @param  string        $optionName   Option holding the base64 key.
     * @param  string        $constantName Constant that overrides the option.
     * @return self
     */
    public static function fromSite(SourceContext $context, string $optionName, string $constantName): self
    {
        $key = null;
        if ($context->constantSet($constantName)) {
            $key = (string) $context->constant($constantName);
        } else {
            $stored = $context->option($optionName, '');
            if (\is_string($stored) && $stored !== '') {
                $decoded = base64_decode($stored, true);
                $key     = $decoded === false ? null : $decoded;
            }
        }
        if ($key !== null && strlen($key) !== \SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            $key = null;
        }

        return new self($key);
    }

    /**
     * Whether a key is available at all.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function hasKey(): bool
    {
        return $this->key !== null;
    }

    /**
     * Open a sealed value.
     *
     * @since 1.0.0
     *
     * @param  string $stored Value as the plugin stored it.
     * @return string|null The password, or null when there is no key or the box does not open.
     */
    public function decrypt(string $stored): ?string
    {
        if ($this->key === null || $stored === '') {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            return null;
        }
        $nonce  = substr($raw, 0, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        try {
            $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        } catch (\SodiumException) {
            return null;
        }

        return $plain === false ? null : $plain;
    }

    /**
     * Seal a value the way the plugins do — for fixtures and tests.
     *
     * @since 1.0.0
     *
     * @param  string $plain Plaintext.
     * @return string
     *
     * @throws \LogicException When the box has no key.
     */
    public function encrypt(string $plain): string
    {
        if ($this->key === null) {
            throw new \LogicException('Cannot seal without a key.');
        }
        $nonce = random_bytes(\SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, $this->key));
    }

    /**
     * Whether a value is shaped like a sealed box: base64 of at least nonce + MAC bytes. A
     * plaintext password never is, so a stored value that still looks sealed was not decoded.
     *
     * @since 1.0.0
     *
     * @param  string $value Stored value.
     * @return bool
     */
    public static function looksSealed(string $value): bool
    {
        if ($value === '' || ! preg_match('#^[A-Za-z0-9+/]+={0,2}$#', $value)) {
            return false;
        }
        $raw = base64_decode($value, true);

        return $raw !== false && strlen($raw) >= \SODIUM_CRYPTO_SECRETBOX_NONCEBYTES + \SODIUM_CRYPTO_SECRETBOX_MACBYTES;
    }

    /**
     * A fresh random key, for tests.
     *
     * @since 1.0.0
     *
     * @return string
     */
    public static function newKey(): string
    {
        return sodium_crypto_secretbox_keygen();
    }
}
