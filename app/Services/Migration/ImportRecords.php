<?php
/**
 * What an import remembers between runs: which draft belongs to which source connection, and
 * how far a log import has come.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Foundation\Application;
use BooleanSmtp\Core\Settings\SettingsRepository;

/**
 * Small, bounded records in the shared options table, keyed by reference: one row per imported
 * source connection (`migration_import`, so a re-run updates the draft instead of creating a
 * twin), one row per source for the log cursor (`migration_log_import`, so an interrupted
 * import resumes) and one row per source for the last import (`migration_run`, so the screens
 * can say a plugin was already imported and ask before importing it again).
 *
 * @since 1.0.0
 */
final class ImportRecords
{
    /** @since 1.0.0 */
    public const CONNECTION_KEY = 'migration_import';
    /** @since 1.0.0 */
    public const CURSOR_KEY = 'migration_log_import';
    /** @since 1.0.0 */
    public const RUN_KEY = 'migration_run';

    /**
     * @since 1.0.0
     *
     * @param SettingsRepository $settings The shared options store.
     * @param DriverInterface    $driver   Database driver, for clearing every record at once.
     * @param Application        $app      Names the plugin the rows belong to.
     */
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly DriverInterface $driver,
        private readonly Application $app,
    ) {}

    /**
     * The draft an earlier run created for a source connection.
     *
     * @since 1.0.0
     *
     * @param  string $source    Source id.
     * @param  string $sourceKey The connection's key inside the source.
     * @return int|null The connection id, or null when no run imported it yet.
     */
    public function connectionFor(string $source, string $sourceKey): ?int
    {
        $record = $this->settings->getByRef(self::CONNECTION_KEY, self::ref($source, $sourceKey));
        if (! \is_array($record) || ($record['source'] ?? null) !== $source || ($record['source_key'] ?? null) !== $sourceKey) {
            return null;
        }

        return isset($record['connection_id']) ? (int) $record['connection_id'] : null;
    }

    /**
     * Remember the draft created or updated for a source connection, and the driver it was
     * written with — an imported log row is linked to a draft by that driver, so it must be the
     * draft's own, not what the source says today.
     *
     * @since 1.0.0
     *
     * @param  string $source       Source id.
     * @param  string $sourceKey    The connection's key inside the source.
     * @param  int    $connectionId The draft's id.
     * @param  string $driver       The draft's transport driver.
     * @return void
     */
    public function rememberConnection(string $source, string $sourceKey, int $connectionId, string $driver = ''): void
    {
        $this->settings->setWithRef(self::CONNECTION_KEY, self::ref($source, $sourceKey), [
            'source'        => $source,
            'source_key'    => $sourceKey,
            'connection_id' => $connectionId,
            'driver'        => $driver,
            'imported_at'   => \gmdate('Y-m-d H:i:s'),
        ], 'entity');
    }

    /**
     * The driver of the draft an earlier run created for a source connection.
     *
     * @since 1.0.0
     *
     * @param  string $source    Source id.
     * @param  string $sourceKey The connection's key inside the source.
     * @return string|null Null when no run imported it yet, or when an older run recorded no driver.
     */
    public function driverFor(string $source, string $sourceKey): ?string
    {
        $record = $this->settings->getByRef(self::CONNECTION_KEY, self::ref($source, $sourceKey));
        $driver = \is_array($record) ? trim((string) ($record['driver'] ?? '')) : '';

        return $driver !== '' ? $driver : null;
    }

    /**
     * The log cursor of a source.
     *
     * @since 1.0.0
     *
     * @param  string $source Source id.
     * @return array<string, mixed>|null The cursor record, or null when no log import ran.
     */
    public function cursor(string $source): ?array
    {
        $record = $this->settings->getByRef(self::CURSOR_KEY, self::ref($source, ''));

        return \is_array($record) && ($record['source'] ?? null) === $source ? $record : null;
    }

    /**
     * Store the log cursor of a source.
     *
     * @since 1.0.0
     *
     * @param  string               $source Source id.
     * @param  array<string, mixed> $cursor The cursor record.
     * @return void
     */
    public function saveCursor(string $source, array $cursor): void
    {
        $cursor['source'] = $source;
        $this->settings->setWithRef(self::CURSOR_KEY, self::ref($source, ''), $cursor, 'entity');
    }

    /**
     * Forget the log cursor of a source, so the next import starts over.
     *
     * @since 1.0.0
     *
     * @param  string $source Source id.
     * @return void
     */
    public function clearCursor(string $source): void
    {
        $this->settings->deleteByRef(self::CURSOR_KEY, self::ref($source, ''));
    }

    /**
     * The last import of a source.
     *
     * @since 1.0.0
     *
     * @param  string $source Source id.
     * @return array{connections_at: string|null, imported: int, replaced: int, kept: int, skipped: int, logs_at: string|null, logs_imported: int}|null
     *         UTC times (`Y-m-d H:i:s`) and counts, or null when the source was never imported.
     */
    public function lastRun(string $source): ?array
    {
        $record = $this->settings->getByRef(self::RUN_KEY, self::ref($source, ''));
        if (! \is_array($record) || ($record['source'] ?? null) !== $source) {
            return null;
        }

        return [
            'connections_at' => isset($record['connections_at']) ? (string) $record['connections_at'] : null,
            'imported'       => (int) ($record['imported'] ?? 0),
            'replaced'       => (int) ($record['replaced'] ?? 0),
            'kept'           => (int) ($record['kept'] ?? 0),
            'skipped'        => (int) ($record['skipped'] ?? 0),
            'logs_at'        => isset($record['logs_at']) ? (string) $record['logs_at'] : null,
            'logs_imported'  => (int) ($record['logs_imported'] ?? 0),
        ];
    }

    /**
     * Record that a source's connections were imported, with what happened to them.
     *
     * @since 1.0.0
     *
     * @param  string $source   Source id.
     * @param  int    $imported New drafts.
     * @param  int    $replaced Site connections replaced.
     * @param  int    $kept     Site connections kept.
     * @param  int    $skipped  Source connections skipped for sharing a sender inside the source.
     * @return void
     */
    public function rememberConnectionRun(string $source, int $imported, int $replaced, int $kept, int $skipped): void
    {
        $this->saveRun($source, [
            'connections_at' => gmdate('Y-m-d H:i:s'),
            'imported'       => $imported,
            'replaced'       => $replaced,
            'kept'           => $kept,
            'skipped'        => $skipped,
        ]);
    }

    /**
     * Record that a source's email log import finished.
     *
     * @since 1.0.0
     *
     * @param  string $source   Source id.
     * @param  int    $imported Rows imported by the finished import.
     * @return void
     */
    public function rememberLogRun(string $source, int $imported): void
    {
        $this->saveRun($source, ['logs_at' => gmdate('Y-m-d H:i:s'), 'logs_imported' => $imported]);
    }

    /**
     * Merge fields into a source's last-import record.
     *
     * @since 1.0.0
     *
     * @param  string               $source Source id.
     * @param  array<string, mixed> $fields Fields to set.
     * @return void
     */
    private function saveRun(string $source, array $fields): void
    {
        $record = $this->settings->getByRef(self::RUN_KEY, self::ref($source, ''));
        $record = \is_array($record) && ($record['source'] ?? null) === $source ? $record : [];
        $this->settings->setWithRef(self::RUN_KEY, self::ref($source, ''), array_merge($record, $fields, ['source' => $source]), 'entity');
    }

    /**
     * Remove every import record.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public function clear(): void
    {
        foreach ([self::CONNECTION_KEY, self::CURSOR_KEY, self::RUN_KEY] as $key) {
            $this->driver->delete('booleanpress_options', ['plugin' => $this->app->pluginSlug(), 'option_name' => $key]);
        }
    }

    /**
     * The reference id for a source connection: a stable hash of source and key.
     *
     * @since 1.0.0
     *
     * @param  string $source    Source id.
     * @param  string $sourceKey The connection's key inside the source; empty for the log cursor.
     * @return int
     */
    private static function ref(string $source, string $sourceKey): int
    {
        return crc32($source . ':' . $sourceKey);
    }
}
