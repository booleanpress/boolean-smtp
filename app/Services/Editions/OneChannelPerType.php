<?php
/**
 * The plugin's notification limit: one channel per type.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\NotificationLimitContract;
use BooleanSmtp\Contracts\TranslatorContract;

/**
 * Allows one channel of each type (one Slack, one Discord, one Telegram).
 *
 * @since 1.0.0
 */
final class OneChannelPerType implements NotificationLimitContract
{
    /**
     * @since 1.0.0
     *
     * @param TranslatorContract $translator Translates the limit message.
     */
    public function __construct(private readonly TranslatorContract $translator) {}

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @return int
     */
    public function maxPerType(string $type): int
    {
        return 1;
    }

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  string $type Channel type identifier.
     * @param  int    $max  The limit that was reached.
     * @return string
     */
    public function limitMessage(string $type, int $max): string
    {
        return $this->translator->translate(
            "The free plugin allows {{max}} '{{type}}' channel. More channels of one type are part of BooleanSMTP Pro.",
            ['max' => $max, 'type' => $type]
        );
    }
}
