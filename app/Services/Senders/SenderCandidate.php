<?php
/**
 * A connection whose sender is being checked.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Senders;

/**
 * The connection being created, given a new sender, activated or imported, as the sender rule
 * sees it.
 *
 * @since 1.0.0
 */
final class SenderCandidate
{
    /**
     * A new connection is being saved.
     *
     * @since 1.0.0
     * @var string
     */
    public const REASON_CREATE = 'create';

    /**
     * An existing connection's sender is being changed.
     *
     * @since 1.0.0
     * @var string
     */
    public const REASON_SENDER_CHANGE = 'sender_change';

    /**
     * An inactive connection is being switched on.
     *
     * @since 1.0.0
     * @var string
     */
    public const REASON_ACTIVATION = 'activation';

    /**
     * A connection is arriving from a migration or a settings file.
     *
     * @since 1.0.0
     * @var string
     */
    public const REASON_IMPORT = 'import';

    /**
     * @since 1.0.0
     *
     * @param string      $sender        Normalized From address.
     * @param string      $driver        Transport driver key.
     * @param string      $providerKey   Provider identity.
     * @param string      $providerLabel Provider name for messages.
     * @param string      $name          Connection name.
     * @param int|null    $connectionId  Id of the connection being changed; null for a new one.
     * @param string      $reason        One of the `REASON_*` constants.
     */
    public function __construct(
        public readonly string $sender,
        public readonly string $driver,
        public readonly string $providerKey,
        public readonly string $providerLabel,
        public readonly string $name,
        public readonly ?int $connectionId,
        public readonly string $reason,
    ) {}

    /**
     * The provider a connection sends through: its driver, and for Custom SMTP the driver plus the
     * lowercased host, so two different SMTP servers count as two providers.
     *
     * @since 1.0.0
     *
     * @param  string               $driver   Transport driver key.
     * @param  array<string, mixed> $settings Decrypted connection settings.
     * @return string
     */
    public static function providerKey(string $driver, array $settings): string
    {
        if ($driver === 'smtp') {
            return 'smtp:' . strtolower(trim((string) ($settings['host'] ?? '')));
        }

        return $driver;
    }
}
