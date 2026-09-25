<?php
/**
 * A connection as the sender rule sees it.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

/**
 * One connection's sender identity: who it is, which address it sends as and through which
 * provider. Built from the site's connections, or from a connection accepted earlier in the same
 * import (which has no id yet).
 *
 * @since 1.0.0
 */
final class SenderEntry
{
    /**
     * @since 1.0.0
     *
     * @param int|null $id            Connection id; null for a connection not saved yet.
     * @param string   $name          Connection name.
     * @param string   $sender        Normalized From address.
     * @param string   $driver        Transport driver key.
     * @param string   $providerKey   Provider identity (see {@see SenderCandidate::providerKey()}).
     * @param string   $providerLabel Provider name for messages, for example `Custom SMTP smtp.example.com`.
     * @param bool     $isActive      Whether the connection is active.
     */
    public function __construct(
        public readonly ?int $id,
        public readonly string $name,
        public readonly string $sender,
        public readonly string $driver,
        public readonly string $providerKey,
        public readonly string $providerLabel,
        public readonly bool $isActive,
    ) {}
}
