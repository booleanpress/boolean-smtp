<?php
/**
 * Edition policy: which connections may share a From address.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Contracts\Editions;

use BooleanSmtp\Services\Editions\SenderCandidate;
use BooleanSmtp\Services\Editions\SenderConflict;
use BooleanSmtp\Services\Editions\SenderEntry;

/**
 * Decides whether a connection may use a sender that other connections already use. The plugin
 * binds its own implementation; an add-on may bind another. Not a public extension point.
 *
 * @internal
 * @since 1.0.0
 */
interface SenderRuleContract
{
    /**
     * Why the candidate cannot use its sender, or null when it can.
     *
     * @since 1.0.0
     *
     * @param  SenderCandidate   $candidate The connection being created, changed, activated or imported.
     * @param  list<SenderEntry> $entries   Every other connection that uses the same sender, the
     *                                      site's first and then any accepted earlier in the same import.
     * @return SenderConflict|null
     */
    public function conflict(SenderCandidate $candidate, array $entries): ?SenderConflict;
}
