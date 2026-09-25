<?php
/**
 * Edition policy: how many notification channels of one type a site may keep.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts\Editions;

/**
 * Answers how many channels of one type (Slack, Discord, Telegram) may exist, and the message
 * shown when the limit is reached. The plugin binds its own implementation; an add-on may bind
 * another. Not a public extension point.
 *
 * @internal
 * @since 1.0.0
 */
interface NotificationLimitContract
{
    /**
     * Maximum number of channels allowed for a channel type.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier, for example `slack`.
     * @return int At least 1.
     */
    public function maxPerType(string $type): int;

    /**
     * Message returned when a site tries to add a channel beyond the limit.
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @param  int    $max  The limit that was reached.
     * @return string
     */
    public function limitMessage(string $type, int $max): string;
}
