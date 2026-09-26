<?php
/**
 * Explains, on the Mailers screen, connections that share a sender the rule does not allow.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Senders;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Models\Connection;

/**
 * A site can hold connections that share a sender the rule would refuse today, saved before the
 * rule existed. Nothing changes how they send; this builds the one-line explanation shown under
 * each of them.
 *
 * @since 1.0.0
 */
final class SenderNotices
{
    /**
     * @since 1.0.0
     *
     * @param Application        $app        Container the sender rule and router are resolved from.
     * @param SenderGuard        $guard      Describes the site's connections.
     * @param TranslatorContract $translator Translates the notices.
     */
    public function __construct(
        private readonly Application $app,
        private readonly SenderGuard $guard,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * The notice for each connection that has one, keyed by connection id.
     *
     * @since 1.0.0
     *
     * @param  iterable<Connection> $connections Every connection on the site.
     * @return array<int, string>
     */
    public function forConnections(iterable $connections): array
    {
        $bySender = [];
        $models   = [];
        foreach ($connections as $connection) {
            $entry = $this->guard->entry($connection);
            if ($entry->sender === '') {
                continue;
            }

            $bySender[$entry->sender][] = $entry;
            $models[(int) $connection->id] = $connection;
        }

        $rule    = $this->app->make(SenderRule::class);
        $router  = $this->app->make(SenderRouter::class);
        $notices = [];

        foreach ($bySender as $sender => $entries) {
            if (count($entries) < 2) {
                continue;
            }

            $active  = array_values(array_filter($entries, static fn (SenderEntry $e): bool => $e->isActive));
            $handler = $router->pick(array_map(static fn (SenderEntry $e): Connection => $models[(int) $e->id], $active), ['from' => $sender]);

            foreach ($entries as $entry) {
                $others = array_values(array_filter($entries, static fn (SenderEntry $e): bool => $e->id !== $entry->id));
                $reason = $entry->isActive ? SenderCandidate::REASON_CREATE : SenderCandidate::REASON_ACTIVATION;
                $check  = new SenderCandidate($entry->sender, $entry->driver, $entry->providerKey, $entry->providerLabel, $entry->name, $entry->id, $reason);

                if ($rule->conflict($check, $others) === null) {
                    continue;
                }

                if (!$entry->isActive) {
                    $with = SenderEntries::firstActive($others);
                    $notices[(int) $entry->id] = $this->translator->translate(
                        "Same sender as '{{name}}'. It can't be activated while '{{name}}' is active.",
                        ['name' => $with?->name ?? '']
                    );
                    continue;
                }

                if ($handler !== null && (int) $handler->id !== $entry->id) {
                    $notices[(int) $entry->id] = $this->translator->translate(
                        "Not used for sender matching — '{{name}}' handles {{sender}}. Still used as a failover.",
                        ['name' => (string) $handler->name, 'sender' => $sender]
                    );
                }
            }
        }

        return $notices;
    }
}
