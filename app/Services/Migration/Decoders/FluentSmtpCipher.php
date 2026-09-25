<?php
/**
 * Decodes the credentials FluentSMTP encrypts with the site's own authentication salts.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Decoders;

/**
 * FluentSMTP stores `base64(iv ‖ openssl_encrypt(value ‖ salt, aes-256-ctr, key))` where the
 * key is `FLUENTMAIL_ENCRYPT_KEY` or `LOGGED_IN_KEY` and the salt `FLUENTMAIL_ENCRYPT_SALT` or
 * `LOGGED_IN_SALT`. The appended salt doubles as the integrity check: a value decrypted with
 * the wrong key does not end with it. The option also carries a canary (`test`) that decrypts
 * to the word `test` while the salts are the ones the values were written with.
 *
 * @since 1.0.0
 */
final class FluentSmtpCipher
{
    /** @since 1.0.0 */
    private const METHOD = 'aes-256-ctr';

    /**
     * @since 1.0.0
     *
     * @param string $key  Encryption key (the site's `LOGGED_IN_KEY` unless overridden).
     * @param string $salt Salt appended to every value before encryption.
     */
    public function __construct(
        private readonly string $key,
        private readonly string $salt,
    ) {}

    /**
     * The cipher for the running site, from its constants — the same fallbacks FluentSMTP uses.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function fromSiteConstants(): self
    {
        $salt = \defined('FLUENTMAIL_ENCRYPT_SALT')
            ? (string) \constant('FLUENTMAIL_ENCRYPT_SALT')
            : ((\defined('LOGGED_IN_SALT') && \constant('LOGGED_IN_SALT') !== '') ? (string) \constant('LOGGED_IN_SALT') : 'this-is-a-fallback-salt-but-not-secure');
        $key = \defined('FLUENTMAIL_ENCRYPT_KEY')
            ? (string) \constant('FLUENTMAIL_ENCRYPT_KEY')
            : ((\defined('LOGGED_IN_KEY') && \constant('LOGGED_IN_KEY') !== '') ? (string) \constant('LOGGED_IN_KEY') : 'this-is-a-fallback-key-but-not-secure');

        return new self($key, $salt);
    }

    /**
     * Decrypt a stored value.
     *
     * @since 1.0.0
     *
     * @param  string $stored Value as FluentSMTP stored it.
     * @return string|null The plaintext, or null when the value was not written with this key and salt.
     */
    public function decrypt(string $stored): ?string
    {
        if ($stored === '' || ! \extension_loaded('openssl')) {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return null;
        }
        $ivLength = (int) openssl_cipher_iv_length(self::METHOD);
        $iv       = substr($raw, 0, $ivLength);
        if (strlen($iv) < $ivLength) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, $ivLength), self::METHOD, $this->key, 0, $iv);
        if ($plain === false || $plain === '' || ! str_ends_with($plain, $this->salt)) {
            return null;
        }

        return substr($plain, 0, -strlen($this->salt));
    }

    /**
     * Encrypt a value the way FluentSMTP does — for fixtures and tests.
     *
     * @since 1.0.0
     *
     * @param  string $plain Plaintext.
     * @return string
     */
    public function encrypt(string $plain): string
    {
        $ivLength = (int) openssl_cipher_iv_length(self::METHOD);
        $iv       = random_bytes($ivLength);
        $cipher   = openssl_encrypt($plain . $this->salt, self::METHOD, $this->key, 0, $iv);

        return base64_encode($iv . (string) $cipher);
    }

    /**
     * Whether the option's canary still decrypts, i.e. the salts have not changed since the
     * credentials were written.
     *
     * @since 1.0.0
     *
     * @param  string $canary The option's `test` value.
     * @return bool
     */
    public function canaryOk(string $canary): bool
    {
        return $this->decrypt($canary) === 'test';
    }
}
