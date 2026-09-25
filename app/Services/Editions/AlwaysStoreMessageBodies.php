<?php
/**
 * The plugin's message-body policy: bodies are always stored.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\MessageBodyStorageContract;

/**
 * Keeps every message body with its log entry, which is what Preview and Resend work from.
 *
 * @since 1.0.0
 */
final class AlwaysStoreMessageBodies implements MessageBodyStorageContract
{
    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function shouldStoreBody(): bool
    {
        return true;
    }
}
