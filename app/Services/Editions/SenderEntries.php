<?php
/**
 * Helpers for choosing among connections that share a sender.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Editions;

/**
 * Picks the connection a conflict message names: an active one before an inactive one, the
 * lower id first, and a saved connection before one accepted earlier in the same import.
 *
 * @since 1.0.0
 */
final class SenderEntries
{
    /**
     * The entry a conflict message names.
     *
     * @since 1.0.0
     *
     * @param  list<SenderEntry> $entries Connections that share the sender.
     * @return SenderEntry|null
     */
    public static function preferred(array $entries): ?SenderEntry
    {
        return self::sorted($entries)[0] ?? null;
    }

    /**
     * The first active entry in the same order.
     *
     * @since 1.0.0
     *
     * @param  list<SenderEntry> $entries Connections that share the sender.
     * @return SenderEntry|null
     */
    public static function firstActive(array $entries): ?SenderEntry
    {
        foreach (self::sorted($entries) as $entry) {
            if ($entry->isActive) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Entries ordered active first, then by id (unsaved last), keeping the given order otherwise.
     *
     * @since 1.0.0
     *
     * @param  list<SenderEntry> $entries Connections that share the sender.
     * @return list<SenderEntry>
     */
    private static function sorted(array $entries): array
    {
        $indexed = array_values($entries);
        $order   = array_keys($indexed);
        usort($order, static function (int $a, int $b) use ($indexed): int {
            $left  = $indexed[$a];
            $right = $indexed[$b];

            return [$left->isActive ? 0 : 1, $left->id ?? PHP_INT_MAX, $a]
                <=> [$right->isActive ? 0 : 1, $right->id ?? PHP_INT_MAX, $b];
        });

        return array_map(static fn (int $i): SenderEntry => $indexed[$i], $order);
    }
}
