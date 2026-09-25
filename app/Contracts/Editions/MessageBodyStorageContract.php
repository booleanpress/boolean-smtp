<?php
/**
 * Edition policy: whether a sent message's body is stored with its log entry.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts\Editions;

/**
 * Decides whether the message body is kept with the email log. Bodies are what Preview and
 * Resend work from. The plugin binds its own implementation; an add-on may bind another. Not a
 * public extension point.
 *
 * @internal
 * @since 1.0.0
 */
interface MessageBodyStorageContract
{
    /**
     * Whether the body of the message being logged is stored.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function shouldStoreBody(): bool;
}
