<?php
/**
 * Contract for writing assessed connections and mapped log rows into the plugin's own storage.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Contracts;

use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessment;

/**
 * The destination owns encryption, the inactive-draft rule, the re-run record and the log
 * dedupe; the runner only sequences the work.
 *
 * @since 1.0.0
 */
interface DestinationInterface
{
    /**
     * Create the draft for an assessed connection, or update the draft an earlier run created
     * for the same source connection.
     *
     * @since 1.0.0
     *
     * @param  ConnectionAssessment $assessment Assessed connection; never `unsupported`.
     * @return int The connection id.
     *
     * @throws \RuntimeException When the row cannot be saved.
     */
    public function importConnection(ConnectionAssessment $assessment): int;

    /**
     * Overwrite an existing site connection with an assessed one, in place: its id, active state
     * and priority stay, its name, driver and settings become the imported ones.
     *
     * @since 1.0.0
     *
     * @param  ConnectionAssessment $assessment   Assessed connection; never `unsupported`.
     * @param  int                  $connectionId The site connection to overwrite.
     * @return int The connection id.
     */
    public function replaceConnection(ConnectionAssessment $assessment, int $connectionId): int;

    /**
     * Store one mapped log row unless a row with its message id already exists.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $row Attributes for the email-log table.
     * @return bool True when stored, false when it already existed.
     */
    public function importLog(array $row): bool;
}
