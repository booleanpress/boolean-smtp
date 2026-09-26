<?php
/**
 * Sequences one import run: assess the source's connections, write the drafts, import one
 * chunk of its log.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessment;
use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessor;
use BooleanSmtp\Services\Migration\Canonical\CanonicalEmailLog;
use BooleanSmtp\Services\Migration\Contracts\DestinationInterface;
use BooleanSmtp\Services\Migration\Contracts\SourceInterface;
use BooleanSmtp\Services\Migration\Logs\LogMapper;
use BooleanSmtp\Services\Migration\Logs\LogWindow;
use BooleanSmtp\Services\Senders\SenderCandidate;
use BooleanSmtp\Services\Senders\SenderEntries;
use BooleanSmtp\Services\Senders\SenderEntry;
use BooleanSmtp\Services\Senders\SenderGuard;

/**
 * Holds the registered sources and runs one of them. A run never writes the source plugin's
 * data, writes the plugin's own tables only outside a dry run, and imports the log in chunks
 * whose cursor survives between runs.
 *
 * @since 1.0.0
 */
final class MigrationRunner
{
    /**
     * Registered sources keyed by id.
     *
     * @since 1.0.0
     * @var array<string, SourceInterface>
     */
    private array $sources = [];

    /**
     * @since 1.0.0
     *
     * @param SourceContext        $context     Site access for the sources.
     * @param DestinationInterface $destination Writes drafts and log rows.
     * @param ConnectionAssessor   $assessor    Assesses each source connection.
     * @param LogMapper            $mapper      Maps log rows to the plugin's table.
     * @param ImportRecords        $records     Re-run records and log cursors.
     * @param SenderGuard|null     $senders     Applies the sender rule; null skips the check.
     */
    public function __construct(
        private readonly SourceContext $context,
        private readonly DestinationInterface $destination,
        private readonly ConnectionAssessor $assessor,
        private readonly LogMapper $mapper,
        private readonly ImportRecords $records,
        private readonly ?SenderGuard $senders = null,
    ) {}

    /**
     * Register a source.
     *
     * @since 1.0.0
     *
     * @param  SourceInterface $source Source to register.
     * @return void
     */
    public function register(SourceInterface $source): void
    {
        $this->sources[$source->id()] = $source;
    }

    /**
     * A registered source.
     *
     * @since 1.0.0
     *
     * @param  string $id Source id.
     * @return SourceInterface|null
     */
    public function getSource(string $id): ?SourceInterface
    {
        return $this->sources[$id] ?? null;
    }

    /**
     * The registered source ids.
     *
     * @since 1.0.0
     *
     * @return list<string>
     */
    public function registeredSourceIds(): array
    {
        return array_keys($this->sources);
    }

    /**
     * How many connections and log rows a source would offer, for the scan.
     *
     * @since 1.0.0
     *
     * @param  string    $sourceId Source id.
     * @param  LogWindow $window   Date bound for the log count.
     * @return array{connections: int, logs: int|null, logs_available: bool, logs_reason: string|null}
     */
    public function previewCounts(string $sourceId, LogWindow $window): array
    {
        $source = $this->sources[$sourceId] ?? null;
        if ($source === null || ! $source->isAvailable($this->context)) {
            return ['connections' => 0, 'logs' => null, 'logs_available' => false, 'logs_reason' => null];
        }
        $availability = $source->logsAvailable($this->context);

        return [
            'connections'    => \count($source->extractConnections($this->context)),
            'logs'           => $availability->available ? $source->countLogs($this->context, $window) : null,
            'logs_available' => $availability->available,
            'logs_reason'    => $availability->reason,
        ];
    }

    /**
     * Run one import.
     *
     * @since 1.0.0
     *
     * @param  string           $sourceId Source id.
     * @param  MigrationOptions $options  What the run does.
     * @return MigrationResult
     */
    public function run(string $sourceId, MigrationOptions $options): MigrationResult
    {
        $source = $this->sources[$sourceId] ?? null;
        if ($source === null) {
            return MigrationResult::error($sourceId, 'Unknown migration source: ' . $sourceId);
        }
        if (! $source->isAvailable($this->context)) {
            return MigrationResult::error($sourceId, 'Source not available or has no data to import.');
        }

        $errors      = [];
        $connections = [];
        if ($options->importConnections) {
            $connections = $this->runConnections($source, $options, $errors);
        }

        $logs = [];
        if ($options->importLogs) {
            $logs = $this->runLogs($source, $options, $errors);
        }

        return new MigrationResult(
            source: $sourceId,
            dryRun: $options->dryRun,
            connections: $connections,
            logs: $logs,
            suggestions: $source->suggestions($this->context),
            errors: $errors,
        );
    }

    /**
     * Assess every source connection and, outside a dry run, write the importable ones.
     *
     * @since 1.0.0
     *
     * @param  SourceInterface  $source  The source.
     * @param  MigrationOptions $options The run's options.
     * @param  list<string>     $errors  Collects write failures.
     * @return list<ConnectionAssessment>
     */
    private function runConnections(SourceInterface $source, MigrationOptions $options, array &$errors): array
    {
        $assessed = [];
        foreach ($source->extractConnections($this->context) as $canonical) {
            $assessment = $this->assessor->assess($canonical);

            /**
             * Filters the assessment of a connection read from another SMTP plugin, before any
             * draft is written.
             *
             * @since 1.0.0
             *
             * @param ConnectionAssessment $assessment The assessment: driver, settings, status, missing keys, issues.
             * @param string               $source     Migration source id.
             * @return ConnectionAssessment The assessment to use.
             */
            $filtered = \apply_filters('boolean_smtp_migration_connection_assessed', $assessment, $source->id());
            if ($filtered instanceof ConnectionAssessment) {
                $assessment = $filtered;
            }

            $assessed[] = $assessment;
        }

        // The source's default connection is considered first, so when several of its
        // connections share a sender it is the one kept.
        $order = array_keys($assessed);
        usort($order, static fn (int $a, int $b): int
            => [$assessed[$a]->wasDefault ? 0 : 1, $a] <=> [$assessed[$b]->wasDefault ? 0 : 1, $b]);

        $pending = [];
        foreach ($order as $index) {
            $assessment = $assessed[$index];
            if (! $assessment->importable()) {
                continue;
            }

            $decision = $this->senderDecision($assessment, $options, $pending);
            if ($options->dryRun || $decision === 'kept' || $decision === 'skipped') {
                $assessment->outcome = $options->dryRun ? null : $decision;
                continue;
            }

            try {
                $assessment->connectionId = $decision === 'replaced'
                    ? $this->destination->replaceConnection($assessment, (int) $assessment->senderConflict['existing_id'])
                    : $this->destination->importConnection($assessment);
                $assessment->outcome = $decision;
            } catch (\Throwable $e) {
                $errors[] = sprintf('%s: %s', $assessment->name, $e->getMessage());
            }
        }

        return $assessed;
    }

    /**
     * Apply the sender rule to one assessed connection: against the connections of the same
     * source accepted before it (a clash skips it), then against the site's connections (a clash
     * is resolved by the user's answer, `keep` by default).
     *
     * @since 1.0.0
     *
     * @param  ConnectionAssessment $assessment The assessed connection; its `senderConflict` and `skippedReason` are set here.
     * @param  MigrationOptions     $options    The run's options, carrying the answers.
     * @param  array<string, list<SenderEntry>> $pending Connections of this source accepted so far, by sender.
     * @return string `imported`, `replaced`, `kept` or `skipped`.
     */
    private function senderDecision(ConnectionAssessment $assessment, MigrationOptions $options, array &$pending): string
    {
        if ($this->senders === null) {
            return 'imported';
        }

        $previousId = $this->records->connectionFor($assessment->source, $assessment->sourceKey);
        $candidate  = $this->senders->candidate(
            (string) $assessment->driver,
            $assessment->settings,
            $assessment->name,
            $previousId,
            SenderCandidate::REASON_IMPORT
        );
        if ($candidate->sender === '') {
            return 'imported';
        }

        $sameSource = $pending[$candidate->sender] ?? [];
        if ($sameSource !== [] && $this->senders->ruleConflict($candidate, $sameSource) !== null) {
            $assessment->skippedReason = SenderEntries::preferred($sameSource)?->name ?? '';

            return 'skipped';
        }
        $pending[$candidate->sender][] = SenderGuard::entryFromCandidate($candidate);

        $conflict = $this->senders->check($candidate);
        if ($conflict === null || $conflict->with->id === null) {
            return 'imported';
        }

        $assessment->senderConflict = [
            'existing_id'     => $conflict->with->id,
            'existing_name'   => $conflict->with->name,
            'sender'          => $candidate->sender,
            'replace_allowed' => true,
            'message'         => $conflict->message,
        ];

        return $options->resolutionFor($assessment->sourceKey) === 'replace' ? 'replaced' : 'kept';
    }

    /**
     * Import one chunk of the source's log, continuing from the stored cursor.
     *
     * @since 1.0.0
     *
     * @param  SourceInterface  $source  The source.
     * @param  MigrationOptions $options The run's options.
     * @param  list<string>     $errors  Collects write failures.
     * @return array<string, mixed> Availability and progress.
     */
    private function runLogs(SourceInterface $source, MigrationOptions $options, array &$errors): array
    {
        $availability = $source->logsAvailable($this->context);
        $window       = LogWindow::forRetention($options->retentionDays, $options->maxLogRows);
        $progress     = $availability->toArray() + [
            'total'    => 0,
            'imported' => 0,
            'skipped'  => ['pending' => 0, 'existing' => 0, 'invalid' => 0],
            'cursor'   => null,
            'done'     => true,
            'window'   => ['since' => $window->sinceUtc, 'cap' => $window->cap],
        ];
        if (! $availability->available) {
            return $progress;
        }

        if ($options->dryRun) {
            $total             = $source->countLogs($this->context, $window);
            $progress['total'] = $window->cap > 0 ? min($total, $window->cap) : $total;
            $progress['rows_in_window'] = $total;
            $progress['done']  = false;

            return $progress;
        }

        // An unfinished cursor is continued; a finished one, or `restart_logs`, starts a new pass
        // over the window in which rows already present are skipped by their message id.
        $cursor = $options->restartLogs ? null : $this->records->cursor($source->id());
        if ($cursor === null || ! empty($cursor['done'])) {
            $total   = $source->countLogs($this->context, $window);
            $startId = $source->logStartId($this->context, $window);
            $cursor  = [
                'start_id'   => $startId,
                'after_id'   => $startId === null ? 0 : $startId - 1,
                'total'      => $window->cap > 0 ? min($total, $window->cap) : $total,
                'imported'   => 0,
                'skipped'    => ['pending' => 0, 'existing' => 0, 'invalid' => 0],
                'done'       => $startId === null,
                'started_at' => \gmdate('Y-m-d H:i:s'),
            ];
        }

        if (empty($cursor['done'])) {
            [$byDriver, $bySource] = $this->connectionMaps($source);
            $chunk = $source->extractLogs($this->context, $window, (int) $cursor['after_id'], $options->chunkSize);
            foreach ($chunk as $log) {
                $cursor['after_id'] = $log->sourceLogId;
                if ($log->status === 'pending') {
                    $cursor['skipped']['pending']++;
                    continue;
                }
                $row = $this->mapper->map($log, $source->name(), $byDriver, $bySource);

                /**
                 * Filters an email-log row imported from another SMTP plugin, before it is stored.
                 *
                 * @since 1.0.0
                 *
                 * @param array<string, mixed> $row    Attributes for the email-log table.
                 * @param string               $source Migration source id.
                 * @param CanonicalEmailLog    $log    The row as the source read it.
                 * @return array<string, mixed> The attributes to store.
                 */
                $row = \apply_filters('boolean_smtp_migration_log_row', $row, $source->id(), $log);
                if (! \is_array($row)) {
                    $cursor['skipped']['invalid']++;
                    continue;
                }
                try {
                    if ($this->destination->importLog($row)) {
                        $cursor['imported']++;
                    } else {
                        $cursor['skipped']['existing']++;
                    }
                } catch (\Throwable $e) {
                    $cursor['skipped']['invalid']++;
                    $errors[] = sprintf('Log row %d: %s', $log->sourceLogId, $e->getMessage());
                }
            }
            $cursor['done'] = \count($chunk) < $options->chunkSize;
            if ($cursor['done']) {
                $cursor['finished_at'] = \gmdate('Y-m-d H:i:s');
            }
            $this->records->saveCursor($source->id(), $cursor);
        }

        return array_merge($progress, [
            'total'    => (int) $cursor['total'],
            'imported' => (int) $cursor['imported'],
            'skipped'  => $cursor['skipped'],
            'cursor'   => (int) $cursor['after_id'],
            'done'     => (bool) $cursor['done'],
        ]);
    }

    /**
     * The two maps an imported log row is linked through, each holding only what is
     * unambiguous: the source plugin's own mailer key → draft id (tried first, because a relay
     * conversion can give two of its connections the same driver of ours), and the draft's own
     * driver → draft id. The driver is the one recorded when the draft was written, not the one
     * the source reports today: a site that switched its old plugin to another provider after an
     * import would otherwise hang every log row on the wrong connection.
     *
     * @since 1.0.0
     *
     * @param  SourceInterface $source The source.
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    private function connectionMaps(SourceInterface $source): array
    {
        $byDriver = [];
        $bySource = [];
        foreach ($source->extractConnections($this->context) as $canonical) {
            $id = $this->records->connectionFor($source->id(), $canonical->sourceKey);
            if ($id === null) {
                continue;
            }
            $driver = $this->records->driverFor($source->id(), $canonical->sourceKey) ?? $canonical->driver;
            if ($driver !== null) {
                $byDriver[$driver][] = $id;
            }
            $bySource[strtolower(trim($canonical->sourceMailer))][] = $id;
        }

        return [$this->unambiguous($byDriver), $this->unambiguous($bySource)];
    }

    /**
     * Keep only the keys that name exactly one draft.
     *
     * @since 1.0.0
     *
     * @param  array<string, list<int>> $grouped Ids grouped by key.
     * @return array<string, int>
     */
    private function unambiguous(array $grouped): array
    {
        $map = [];
        foreach ($grouped as $key => $ids) {
            if ($key !== '' && \count($ids) === 1) {
                $map[$key] = $ids[0];
            }
        }

        return $map;
    }
}
