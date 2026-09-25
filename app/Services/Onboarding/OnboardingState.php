<?php

/**
 * Typed read/write access to the guided-onboarding progress record.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Onboarding;

use BooleanSmtp\Core\Settings\SettingsRepository;

/**
 * The onboarding progress record stored under `onboarding` in the shared options table.
 *
 * The record carries one boolean per wizard step (`start`, `provider`, `connect`, `verify`,
 * `review`), the dashboard checklist's `send_test` flag (true once any test email has been
 * accepted), `verify_skipped`, the draft and applied connection ids, the migration source the
 * wizard is importing from, and `dismissed_at`. Unknown keys are dropped on every write, so a
 * record written by an earlier layout is normalised the first time it is saved.
 *
 * @since 1.0.0
 */
final class OnboardingState
{
    /**
     * Option key the record is stored under.
     *
     * @since 1.0.0
     * @var string
     */
    public const OPTION_KEY = 'onboarding';

    /**
     * Wizard step keys, in rail order.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const STEP_KEYS = ['start', 'provider', 'connect', 'verify', 'review'];

    /**
     * Every key of the record with its default value; the value's type is the key's type.
     *
     * @since 1.0.0
     * @var array<string, bool|int|string|null>
     */
    private const DEFAULTS = [
        'start'                 => false,
        'provider'              => false,
        'connect'               => false,
        'verify'                => false,
        'review'                => false,
        'send_test'             => false,
        'verify_skipped'        => false,
        'draft_connection_id'   => null,
        'applied_connection_id' => null,
        'migration_source'      => null,
        'dismissed_at'          => null,
    ];

    /**
     * Keys whose value is a connection id (a positive integer or null).
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const ID_KEYS = ['draft_connection_id', 'applied_connection_id'];

    /**
     * Keys whose value is a short string or null.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const STRING_KEYS = ['migration_source', 'dismissed_at'];

    /**
     * @since 1.0.0
     *
     * @param SettingsRepository $store The shared options store the record lives in.
     */
    public function __construct(private readonly SettingsRepository $store)
    {
    }

    /**
     * Every key of the record, in contract order.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::DEFAULTS);
    }

    /**
     * The stored record merged over the defaults, every value cast to its key's type.
     *
     * @since 1.0.0
     *
     * @return array<string, bool|int|string|null>
     */
    public function all(): array
    {
        $stored = $this->store->get(self::OPTION_KEY, []);

        if (\is_string($stored)) {
            $stored = json_decode($stored, true) ?: [];
        }

        return self::normalize(\is_array($stored) ? $stored : []);
    }

    /**
     * Merge changes into the record, persist it, and return the full normalised record.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $changes Keys to change; unknown keys are ignored.
     * @return array<string, bool|int|string|null>
     */
    public function update(array $changes): array
    {
        $next = self::normalize(array_merge($this->all(), $changes));
        $this->store->set(self::OPTION_KEY, $next);

        return $next;
    }

    /**
     * Delete the record so the next read returns the defaults.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function reset(): void
    {
        $this->store->delete(self::OPTION_KEY);
    }

    /**
     * Drop unknown keys and cast every known key to its type.
     *
     * Booleans accept the usual truthy forms; ids keep positive integers and become null
     * otherwise; strings are trimmed and become null when empty.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $raw The record as stored or as received from a client.
     * @return array<string, bool|int|string|null>
     */
    public static function normalize(array $raw): array
    {
        $out = [];

        foreach (self::DEFAULTS as $key => $default) {
            $value = $raw[$key] ?? $default;

            if (\in_array($key, self::ID_KEYS, true)) {
                $id        = \is_numeric($value) ? (int) $value : 0;
                $out[$key] = $id > 0 ? $id : null;
                continue;
            }

            if (\in_array($key, self::STRING_KEYS, true)) {
                $string    = \is_scalar($value) ? trim((string) $value) : '';
                $out[$key] = $string === '' ? null : $string;
                continue;
            }

            $out[$key] = \is_string($value)
                ? \in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true)
                : (bool) $value;
        }

        return $out;
    }
}
