<?php

/**
 * Symmetric encryption for connection secrets stored in the database.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Encryption;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Core\Contracts\LoggerContract;
use BooleanSmtp\Support\Settings;

/**
 * AES-256-CBC encryption with an HMAC-SHA256 integrity tag, used to store connection credentials
 * and other secrets at rest.
 *
 * The key is derived by SHA-256 hashing the first available of, in order: the
 * `BOOLEAN_SMTP_ENCRYPTION_KEY` constant, the WordPress `AUTH_KEY` constant (unless it still holds
 * the placeholder WordPress ships with), or a random key generated once for the site and kept in
 * the plugin's settings. No two installs ever share a key.
 *
 * @since 1.0.0
 */
class AesEncryptor implements EncryptorContract
{
    /**
     * OpenSSL cipher used for encryption and decryption.
     *
     * @since 1.0.0
     */
    private const CIPHER = 'aes-256-cbc';

    /**
     * Hash algorithm used to compute the HMAC integrity tag.
     *
     * @since 1.0.0
     */
    private const HMAC_ALGO = 'sha256';

    /**
     * The `AUTH_KEY` value WordPress ships in its sample configuration; treated as undefined.
     *
     * @since 1.0.0
     */
    private const WORDPRESS_PLACEHOLDER_KEY = 'put your unique phrase here';

    /**
     * Key under which {@see decryptArray()} lists the fields that failed to decrypt.
     *
     * @since 1.0.0
     */
    public const DECRYPT_FAILED_KEY = '_decrypt_failed';

    /**
     * Derived encryption key: a SHA-256 hash of the configured key material, used for both
     * encryption and the HMAC tag.
     *
     * @since 1.0.0
     * @var string
     */
    private string $key;

    /**
     * Derive the encryption key from configuration.
     *
     * Key source order: the `BOOLEAN_SMTP_ENCRYPTION_KEY` constant, then the WordPress `AUTH_KEY`
     * constant, then the site's own generated key (32 random bytes, created on first use and
     * stored as the `encryption_key` setting). The chosen value is hashed with SHA-256 before use,
     * so the key is always 32 bytes regardless of the source string's length.
     *
     * @since 1.0.0
     *
     * @param Settings|null       $settings Plugin settings holding the generated key; without it (a
     *                                      bare instance in a test) the generated key lives for the
     *                                      instance only.
     * @param LoggerContract|null $logger   Receives a warning per field that fails to decrypt.
     */
    public function __construct(
        private readonly ?Settings $settings = null,
        private readonly ?LoggerContract $logger = null,
    ) {
        $this->key = hash('sha256', $this->keyMaterial(), true);
    }

    /**
     * The configured key material, in the documented order.
     *
     * @since 1.0.0
     *
     * @return string
     */
    private function keyMaterial(): string
    {
        if (defined('BOOLEAN_SMTP_ENCRYPTION_KEY') && (string) BOOLEAN_SMTP_ENCRYPTION_KEY !== '') {
            return (string) BOOLEAN_SMTP_ENCRYPTION_KEY;
        }

        if (defined('AUTH_KEY') && (string) AUTH_KEY !== '' && (string) AUTH_KEY !== self::WORDPRESS_PLACEHOLDER_KEY) {
            return (string) AUTH_KEY;
        }

        return $this->generatedKey();
    }

    /**
     * The key generated for this site, created and stored on first use.
     *
     * @since 1.0.0
     *
     * @return string 64 hex characters.
     */
    private function generatedKey(): string
    {
        $stored = $this->settings !== null ? (string) $this->settings->get('encryption_key', '') : '';
        if ($stored !== '') {
            return $stored;
        }

        $generated = bin2hex(random_bytes(32));
        $this->settings?->set('encryption_key', $generated);

        return $generated;
    }

    /**
     * Encrypt a value with AES-256-CBC.
     *
     * Generates a random IV for each call and computes an HMAC-SHA256 tag over the IV and
     * ciphertext. The return value is the base64 encoding of the HMAC tag, IV, and ciphertext
     * concatenated in that order.
     *
     * @since 1.0.0
     *
     * @param  string $value Plaintext value to encrypt.
     * @return string Base64-encoded payload containing the HMAC tag, IV, and ciphertext.
     *
     * @throws \RuntimeException When OpenSSL encryption fails.
     */
    public function encrypt(string $value): string
    {
        $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(self::CIPHER));

        $encrypted = openssl_encrypt($value, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($encrypted === false) {
            throw new \RuntimeException('Encryption failed.');
        }

        $hmac = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $this->key, true);

        return base64_encode($hmac . $iv . $encrypted);
    }

    /**
     * Decrypt a value previously produced by {@see encrypt()}.
     *
     * Verifies the HMAC tag before attempting decryption. A payload that is not valid base64, is
     * too short to contain a tag and IV, or fails HMAC verification throws instead of returning a
     * partial or incorrect result.
     *
     * @since 1.0.0
     *
     * @param  string $payload Base64-encoded payload produced by {@see encrypt()}.
     * @return string The decrypted plaintext.
     *
     * @throws \RuntimeException When the payload is not valid base64, is too short, fails HMAC
     *                            verification, or OpenSSL decryption fails.
     */
    public function decrypt(string $payload): string
    {
        $decoded = base64_decode($payload, true);

        if ($decoded === false) {
            throw new \RuntimeException('Invalid encrypted payload.');
        }

        $hmacLength = 32; // SHA-256 output
        $ivLength   = openssl_cipher_iv_length(self::CIPHER);

        if (strlen($decoded) < $hmacLength + $ivLength) {
            throw new \RuntimeException('Payload too short.');
        }

        $hmac      = substr($decoded, 0, $hmacLength);
        $iv        = substr($decoded, $hmacLength, $ivLength);
        $encrypted = substr($decoded, $hmacLength + $ivLength);

        $calculatedHmac = hash_hmac(self::HMAC_ALGO, $iv . $encrypted, $this->key, true);

        if (!hash_equals($calculatedHmac, $hmac)) {
            throw new \RuntimeException('HMAC verification failed — payload may be tampered.');
        }

        $decrypted = openssl_decrypt($encrypted, self::CIPHER, $this->key, OPENSSL_RAW_DATA, $iv);

        if ($decrypted === false) {
            throw new \RuntimeException('Decryption failed.');
        }

        return $decrypted;
    }

    /**
     * Recursively encrypt sensitive values in a settings array.
     *
     * Applies {@see isSensitiveKey()} to decide which keys are encrypted; non-sensitive and
     * non-string values are returned unchanged.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function encryptArray(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if ($key === self::DECRYPT_FAILED_KEY) {
                continue;
            }

            if (is_string($value) && $this->isSensitiveKey($key)) {
                $result[$key] = $this->encrypt($value);
            } elseif (is_array($value)) {
                $result[$key] = $this->encryptArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * Recursively decrypt sensitive values in a settings array.
     *
     * Applies {@see isSensitiveKey()} to decide which keys are decrypted. A value that fails to
     * decrypt (the key changed, the row was copied from another site, the payload was altered)
     * comes back as an empty string — never as the ciphertext, which a later save would persist as
     * the secret — and its key is listed under {@see DECRYPT_FAILED_KEY}; a warning names the field.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function decryptArray(array $data): array
    {
        $result = [];
        $failed = [];

        foreach ($data as $key => $value) {
            if ($key === self::DECRYPT_FAILED_KEY) {
                continue;
            }

            if (is_string($value) && $this->isSensitiveKey($key)) {
                if ($value === '') {
                    $result[$key] = '';
                    continue;
                }

                try {
                    $result[$key] = $this->decrypt($value);
                } catch (\Throwable) {
                    $result[$key] = '';
                    $failed[]     = (string) $key;
                    $this->logger?->warning('Stored secret could not be decrypted and was blanked; re-enter it.', ['field' => $key]);
                }
            } elseif (is_array($value)) {
                $result[$key] = $this->decryptArray($value);
            } else {
                $result[$key] = $value;
            }
        }

        if ($failed !== []) {
            $result[self::DECRYPT_FAILED_KEY] = $failed;
        }

        return $result;
    }

    /**
     * Determine whether a settings key holds a sensitive value that must be encrypted at rest.
     *
     * Matches by substring against a small set of fragments rather than an exact-match list of
     * key names. Connection settings use mode-specific field names for the same kind of secret
     * (for example `api_access_key`, `smtp_username`, `smtp_password`, `one_click_bearer_token`);
     * a fragment match catches all of them without keeping an exact-match list in sync as new
     * transports and field names are added. This method is part of {@see EncryptorContract} so
     * other components that need to know whether a field is a secret (for example when masking
     * settings in an API response) share this single implementation instead of maintaining their
     * own list. A custom transport declares the exact names of its own credential fields through
     * the `boolean_smtp_sensitive_keys` filter; those are added to the built-in set, never
     * substituted for it.
     *
     * @since 1.0.0
     *
     * @param  string $key Settings key to check.
     * @return bool True when the key name matches a known secret fragment or exact name.
     */
    public function isSensitiveKey(string $key): bool
    {
        $sensitiveFragments = ['key', 'secret', 'password', 'token', 'username'];

        $normalized = strtolower($key);

        if (in_array($normalized, $this->sensitiveKeys(), true)) {
            return true;
        }

        foreach ($sensitiveFragments as $fragment) {
            if (str_contains($normalized, $fragment)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The exact key names treated as secrets, in addition to the fragment match: the built-in
     * names plus whatever the `boolean_smtp_sensitive_keys` filter adds.
     *
     * @since 1.0.0
     *
     * @return list<string> Lowercase key names.
     */
    protected function sensitiveKeys(): array
    {
        $builtIn = ['webhook_url'];

        if (!function_exists('apply_filters')) {
            return $builtIn;
        }

        /**
         * Filters the exact settings-key names whose values are encrypted at rest, masked in
         * REST responses and redacted from logs.
         *
         * Keys containing `key`, `secret`, `password`, `token` or `username` are always secrets;
         * a custom transport whose credential fields are named differently declares them here.
         * The names returned are added to the built-in set: a listener can add keys, never make
         * a built-in secret visible.
         *
         * @since 1.0.0
         *
         * @param  list<string> $keys Exact key names treated as secrets. Default `['webhook_url']`.
         * @return list<string> The key names to add to the built-in set.
         */
        $filtered = apply_filters('boolean_smtp_sensitive_keys', $builtIn);
        if (!is_array($filtered)) {
            return $builtIn;
        }

        $keys = $builtIn;
        foreach ($filtered as $key) {
            if (is_string($key) && $key !== '') {
                $keys[] = strtolower($key);
            }
        }

        return array_values(array_unique($keys));
    }
}
