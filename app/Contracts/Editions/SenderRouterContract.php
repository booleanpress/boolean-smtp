<?php
/**
 * Edition policy: which connection sends a message whose sender several connections share.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts\Editions;

use BooleanSmtp\Models\Connection;
use BooleanSmtp\Models\EmailLog;

/**
 * Chooses among connections that share a sender: which one sends, which one is tried at once
 * after a failure, and in which order a failed message is retried. The plugin binds its own
 * implementation; an add-on may bind another. Not a public extension point.
 *
 * @internal
 * @since 1.0.0
 */
interface SenderRouterContract
{
    /**
     * Pick the connection for a message among the active connections that use its sender.
     *
     * @since 1.0.0
     *
     * @param  list<Connection>     $matches   Active connections whose sender is the message's From.
     * @param  array<string, mixed> $emailData Routing data (`to`, `subject`, `from`, `content_type`).
     * @return Connection|null
     */
    public function pick(array $matches, array $emailData): ?Connection;

    /**
     * The next connection to try at once, in the same send, after a connection failed. Asked
     * again after each failed try, until it returns null or the send's limit of tries is reached.
     *
     * @since 1.0.0
     *
     * @param  int|null  $failedConnectionId Connection whose send just failed, when known.
     * @param  list<int> $tried              Connections this message already failed on; never returned.
     * @return int|null Connection id, or null for none.
     */
    public function immediateFallback(?int $failedConnectionId, array $tried = []): ?int;

    /**
     * Order the active connections for a failed message's next attempt.
     *
     * @since 1.0.0
     *
     * @param  list<Connection> $candidates Active connections by priority, then id.
     * @param  EmailLog         $log        Log row of the failed message.
     * @return list<Connection>
     */
    public function retryOrder(array $candidates, EmailLog $log): array;
}
