<?php
/**
 * The plugin's sender routing: the first connection by priority.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\SenderRouterContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;
use BooleanSmtp\Support\Settings;

/**
 * Sends through the first matching connection by priority, then id; after a failure tries the
 * site's fallback connection when fallback is switched on and the message has not failed on it
 * yet; retries in priority order.
 *
 * @since 1.0.0
 */
final class FirstByPriority implements SenderRouterContract
{
    /**
     * @since 1.0.0
     *
     * @param Settings $settings Plugin settings (`fallback_enabled`, `fallback_connection_id`).
     */
    public function __construct(private readonly Settings $settings) {}

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  list<Connection>     $matches   Active connections whose sender is the message's From.
     * @param  array<string, mixed> $emailData Routing data.
     * @return Connection|null
     */
    public function pick(array $matches, array $emailData): ?Connection
    {
        usort($matches, static fn (Connection $a, Connection $b): int
            => [(int) $a->priority, (int) $a->id] <=> [(int) $b->priority, (int) $b->id]);

        return $matches[0] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  int|null  $failedConnectionId Connection whose send just failed.
     * @param  list<int> $tried              Connections this message already failed on.
     * @return int|null
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

        return $fallbackId;
    }

    /**
     * {@inheritDoc}
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
