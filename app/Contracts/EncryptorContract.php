<?php
/**
 * Contract for encrypting and decrypting sensitive settings values.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts;

/**
 * Guarantees a way to encrypt and decrypt values, and to identify which settings keys hold
 * sensitive data that must be encrypted at rest and masked in output.
 *
 * @since 1.0.0
 */
interface EncryptorContract
{
    /**
     * Encrypt a value.
     *
     * @since 1.0.0
     *
     * @param  string $value Plain-text value.
     * @return string The encrypted payload.
     */
    public function encrypt(string $value): string;

    /**
     * Decrypt a value.
     *
     * @since 1.0.0
     *
     * @param  string $payload Encrypted payload previously produced by encrypt().
     * @return string The decrypted plain-text value.
     */
    public function decrypt(string $payload): string;

    /**
     * Encrypt every sensitive string value in an array.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Key/value pairs; keys identified by isSensitiveKey() are
     *                                     encrypted.
     * @return array<string, mixed> The same keys, with sensitive string values encrypted.
     */
    public function encryptArray(array $data): array;

    /**
     * Decrypt every sensitive string value in an array.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Key/value pairs; keys identified by isSensitiveKey() are
     *                                     decrypted.
     * @return array<string, mixed> The same keys, with sensitive string values decrypted.
     */
    public function decryptArray(array $data): array;

    /**
     * Determine whether a settings key is credential-shaped and must be treated as sensitive.
     *
     * A sensitive key is encrypted at rest and masked rather than returned verbatim in an API
     * response. This is the single source of truth for that judgment, shared by encryptArray()
     * and decryptArray() and by any caller that needs to make the same decision about a decrypted
     * value without keeping its own separate list.
     *
     * @since 1.0.0
     *
     * @param  string $key Settings key to check.
     * @return bool True when the key holds sensitive data.
     */
    public function isSensitiveKey(string $key): bool;
}
