<?php
/**
 * The reason a connection may not use its sender.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

/**
 * Names the connection that already uses the sender and carries the message for the user.
 *
 * @since 1.0.0
 */
final class SenderConflict
{
    /**
     * @since 1.0.0
     *
     * @param SenderEntry $with    The connection that already uses the sender.
     * @param string      $message Message for the user, already translated.
     */
    public function __construct(
        public readonly SenderEntry $with,
        public readonly string $message,
    ) {}
}
