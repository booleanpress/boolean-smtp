<?php
/**
 * The plugin's sender rule: one connection per From address.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

use BooleanSmtp\Contracts\Editions\SenderRuleContract;
use BooleanSmtp\Contracts\TranslatorContract;

/**
 * Each sender is used by one connection, active or inactive. A connection that already shares
 * its sender (one saved before this rule, or left by an add-on) can still be edited, but cannot be
 * switched on while another connection with that sender is active.
 *
 * @since 1.0.0
 */
final class OneConnectionPerSender implements SenderRuleContract
{
    /**
     * @since 1.0.0
     *
     * @param TranslatorContract $translator Translates the conflict messages.
     */
    public function __construct(private readonly TranslatorContract $translator) {}

    /**
     * {@inheritDoc}
     *
     * @since 1.0.0
     *
     * @param  SenderCandidate   $candidate The connection being checked.
     * @param  list<SenderEntry> $entries   Other connections that use the same sender.
     * @return SenderConflict|null
     */
    public function conflict(SenderCandidate $candidate, array $entries): ?SenderConflict
    {
        if ($candidate->sender === '') {
            return null;
        }

        if ($candidate->reason === SenderCandidate::REASON_ACTIVATION) {
            $active = SenderEntries::firstActive($entries);

            return $active === null ? null : new SenderConflict($active, $this->translator->translate(
                "'{{name}}' already sends as {{sender}}. Only one active connection per sender.",
                ['name' => $active->name, 'sender' => $candidate->sender]
            ));
        }

        $with = SenderEntries::preferred($entries);
        if ($with === null) {
            return null;
        }

        return new SenderConflict($with, $this->translator->translate(
            "{{sender}} already sends through '{{name}}'. Each sender uses one connection — edit '{{name}}' to change its provider.",
            ['sender' => $candidate->sender, 'name' => $with->name]
        ));
    }
}
