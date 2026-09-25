<?php
/**
 * Decodes the SMTP password of Easy WP SMTP 1.x, which used its own AES helper keyed by a
 * salt it stored in an option.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Decoders;

/**
 * Easy WP SMTP 1.x stored `base64(iv ‖ AES-256-CTR(password))` with the key
 * `sha256(option swpsmtp_enc_key)` in raw form when the option `swpsmtp_pass_encrypted` was
 * set; otherwise the password was base64 (round-trip checked) or plain. This class is that
 * decision, as the 2.x converter documents it.
 *
 * @since 1.0.0
 */
final class LegacyEasyWpSmtpCipher
{
    /** @since 1.0.0 */
    private const METHOD = 'aes-256-ctr';

    /**
     * @since 1.0.0
     *
     * @param string $encKey The `swpsmtp_enc_key` option value.
     */
    public function __construct(private readonly string $encKey) {}

    /**
     * Decrypt a value written with the 1.x cipher.
     *
     * @since 1.0.0
     *
     * @param  string $stored Stored value.
     * @return string|null The password, or null when the value does not decrypt.
     */
    public function decrypt(string $stored): ?string
    {
        if ($stored === '' || $this->encKey === '' || ! \extension_loaded('openssl')) {
            return null;
        }
        $raw = base64_decode($stored, true);
        if ($raw === false) {
            return null;
        }
        $ivLength = (int) openssl_cipher_iv_length(self::METHOD);
        if (strlen($raw) <= $ivLength) {
            return null;
        }
        $keyHash = openssl_digest($this->encKey, 'sha256', true);
        $plain   = openssl_decrypt(substr($raw, $ivLength), self::METHOD, (string) $keyHash, \OPENSSL_RAW_DATA, substr($raw, 0, $ivLength));

        return $plain === false ? null : $plain;
    }

    /**
     * Encrypt a value the way 1.x did — for fixtures and tests.
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
        $keyHash  = openssl_digest($this->encKey, 'sha256', true);

        return base64_encode($iv . (string) openssl_encrypt($plain, self::METHOD, (string) $keyHash, \OPENSSL_RAW_DATA, $iv));
    }

    /**
     * The 1.x password for a stored value and the `swpsmtp_pass_encrypted` flag: the cipher
     * when flagged, otherwise base64 only when it round-trips to readable text, otherwise plain.
     *
     * @since 1.0.0
     *
     * @param  string $stored    Stored value.
     * @param  bool   $encrypted The `swpsmtp_pass_encrypted` flag.
     * @return string|null The password, or null when the cipher was flagged and did not decrypt.
     */
    public function password(string $stored, bool $encrypted): ?string
    {
        if ($encrypted) {
            return $this->decrypt($stored);
        }
        $decoded = base64_decode($stored, true);
        if ($decoded !== false && base64_encode($decoded) === $stored && mb_check_encoding($decoded, 'UTF-8') && ! preg_match('/[^\x20-\x7E]/', $decoded)) {
            return $decoded;
        }

        return $stored;
    }
}
