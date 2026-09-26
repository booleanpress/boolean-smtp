<?php
/**
 * Which connection sends a message, and which ones are tried after a failure.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Senders;

use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;

/**
 * Sends through the first matching connection by priority, then id; after a failure tries the
 * site's fallback connection when fallback is switched on and the message has not failed on it
 * yet; retries in priority order.
 *
 * @since 1.0.0
 */
class SenderRouter
{
    /**
     * @since 1.0.0
     *
     * @param Settings             $settings    Plugin settings (`fallback_enabled`, `fallback_connection_id`).
     * @param ConnectionRepository $connections Connection lookup for the configured fallback.
     */
    public function __construct(
        private readonly Settings $settings,
        private readonly ConnectionRepository $connections,
    ) {}

    /**
     * Pick the connection for a message among the active connections that use its sender.
     *
     * @since 1.0.0
     *
     * @param  list<Connection>     $matches   Active connections whose sender is the message's From.
     * @param  array<string, mixed> $emailData Routing data (`to`, `subject`, `from`, `content_type`).
     * @return Connection|null
     */
    public function pick(array $matches, array $emailData): ?Connection
    {
        usort($matches, static fn (Connection $a, Connection $b): int
            => [(int) $a->priority, (int) $a->id] <=> [(int) $b->priority, (int) $b->id]);

        return $matches[0] ?? null;
    }

    /**
     * The next connection to try at once, in the same send, after a connection failed: the
     * site's fallback connection, when fallback is on and the message has not failed on it yet.
     *
     * @since 1.0.0
     *
     * @param  int|null  $failedConnectionId Connection whose send just failed, when known.
     * @param  list<int> $tried              Connections this message already failed on; never returned.
     * @return int|null Connection id, or null for none.
     */
    public function immediateFallback(?int $failedConnectionId, array $tried = []): ?int
    {
        if (!$this->settings->get('fallback_enabled')) {
            return null;
        }

        $fallbackId = (int) $this->settings->get('fallback_connection_id');
        if ($fallbackId <= 0 || \in_array($fallbackId, $tried, true)) {
            return null;
        }

        $fallback = $this->connections->find($fallbackId);

        return $fallback !== null && (bool) $fallback->is_active ? $fallbackId : null;
    }

    /**
     * Order the active connections for a failed message's next attempt: priority order.
     *
     * @since 1.0.0
     *
     * @param  list<Connection> $candidates Active connections by priority, then id.
     * @param  EmailLog         $log        Log row of the failed message.
     * @return list<Connection>
     */
    public function retryOrder(array $candidates, EmailLog $log): array
    {
        return array_values($candidates);
    }
}
