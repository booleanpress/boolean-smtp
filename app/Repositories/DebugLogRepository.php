<?php
/**
 * Stores and queries the SMTP debug sessions captured for email log entries.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Core\Pagination\LengthAwarePaginator;

/**
 * Debug sessions as files: one JSON file per email log entry under the sessions directory
 * (by default `wp-content/uploads/booleanpress/boolean-smtp/logs/debug-sessions/`, inside the
 * plugin's log root, whose size ceiling counts these files too). A transcript can hold the SMTP
 * conversation of a send, so the directory is shielded from direct requests: its `.htaccess`
 * denies access where Apache honours it, an `index.php` stops listings, and every file name
 * carries an HMAC of the entry id (`<email_log_id>-<16 hex>.json`) keyed with the site's
 * secret, so a URL cannot be guessed on servers that ignore `.htaccess`. A new capture for the
 * same entry replaces the file. Sessions older than {@see RETENTION_DAYS} days are removed by
 * {@see pruneExpired()}, which the retention job runs daily, and the directory never holds more
 * than {@see MAX_SESSIONS} files. Nothing about debugging touches the database.
 *
 * @since 1.0.0
 */
class DebugLogRepository
{
    /**
     * Days a session is kept before the retention job removes it.
     *
     * @since 1.0.0
     * @var int
     */
    public const RETENTION_DAYS = 7;

    /**
     * Upper bound on stored sessions; the oldest go first when it is exceeded.
     *
     * @since 1.0.0
     * @var int
     */
    public const MAX_SESSIONS = 500;

    /**
     * The names this repository writes, for anything that has to recognise its files (the log
     * manager's pruning, uninstall).
     *
     * @since 1.0.0
     * @var string
     */
    public const FILE_PATTERN = '/^\d+-[0-9a-f]{16}\.json$/';

    /**
     * The resolved file-name secret, once {@see $secret} has been called.
     *
     * @since 1.0.0
     * @var string|null
     */
    private ?string $resolvedSecret = null;

    /**
     * @since 1.0.0
     *
     * @param string        $directory Absolute path of the sessions directory (created on first write).
     * @param \Closure|null $secret    Returns the site secret the file names are keyed with (called once,
     *                                 on first use, so it may depend on WordPress being fully loaded).
     *                                 Rotating the secret orphans existing files until they are pruned.
     * @param \Closure|null $prepare   Runs before the directory is first created — creates the log root
     *                                 and its protection files; returns false when that failed.
     */
    public function __construct(
        private readonly string $directory,
        private readonly ?\Closure $secret = null,
        private readonly ?\Closure $prepare = null,
    ) {
    }

    /**
     * Store the debug lines captured while an email log entry was sent.
     *
     * @since 1.0.0
     *
     * @param  int                              $emailLogId   Email log entry the capture belongs to.
     * @param  array<int, array<string, mixed>> $lines        Captured lines (`level`, `message`, …).
     * @param  int|null                         $connectionId Connection used for the send, if known.
     * @return bool True when the session file was written.
     */
    public function store(int $emailLogId, array $lines, ?int $connectionId = null): bool
    {
        if (empty($lines) || $emailLogId <= 0) {
            return false;
        }

        $session = [
            'email_log_id'  => $emailLogId,
            'connection_id' => $connectionId,
            'level'         => $this->dominantLevel($lines),
            'line_count'    => count($lines),
            'lines'         => array_values($lines),
            'created_at'    => \gmdate('Y-m-d H:i:s'),
        ];

        try {
            if (!$this->ensureDirectory()) {
                throw new \RuntimeException('The debug log directory is not writable: ' . $this->directory);
            }

            $json = json_encode($session, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($json === false || file_put_contents($this->path($emailLogId), $json, LOCK_EX) === false) {
                throw new \RuntimeException('The debug session could not be written for email log ' . $emailLogId);
            }

            $this->enforceSessionCap();

            return true;
        } catch (\Throwable $e) {
            /**
             * Fires when a debug session could not be stored.
             *
             * @since 1.0.0
             *
             * @param string                           $message Failure detail.
             * @param array<int, array<string, mixed>> $lines   The lines that were not stored.
             */
            \do_action('boolean_smtp_debug_log_error', $e->getMessage(), $lines);

            return false;
        }
    }

    /**
     * The session captured for an email log entry.
     *
     * @since 1.0.0
     *
     * @param  int $emailLogId Email log entry.
     * @return array{email_log_id: int, connection_id: int|null, level: string, line_count: int, lines: array<int, array<string, mixed>>, created_at: string|null}|null
     */
    public function getForEmail(int $emailLogId): ?array
    {
        return $this->read($this->path($emailLogId));
    }

    /**
     * Page through sessions, newest first, optionally only those whose dominant level matches.
     *
     * @since 1.0.0
     *
     * @param  int         $page    1-based page number.
     * @param  int         $perPage Rows per page.
     * @param  string|null $level   `error`, `warning`, `debug` or `info` to filter; null for all.
     * @return LengthAwarePaginator Items are session summaries (no lines).
     */
    public function listRecent(int $page = 1, int $perPage = 50, ?string $level = null): LengthAwarePaginator
    {
        $sessions = [];
        foreach ($this->files() as $file) {
            $session = $this->read($file);
            if ($session === null || ($level !== null && $level !== '' && $session['level'] !== $level)) {
                continue;
            }
            unset($session['lines']);
            $session['id'] = $session['email_log_id'];
            $sessions[]    = $session;
        }

        usort($sessions, static fn (array $a, array $b): int => strcmp((string) $b['created_at'], (string) $a['created_at']) ?: $b['id'] <=> $a['id']);

        $perPage = \max(1, $perPage);
        $page    = \max(1, $page);

        return new LengthAwarePaginator(array_slice($sessions, ($page - 1) * $perPage, $perPage), count($sessions), $perPage, $page);
    }

    /**
     * Delete every session file.
     *
     * @since 1.0.0
     *
     * @return int The number of deleted sessions.
     */
    public function clear(): int
    {
        $deleted = 0;
        foreach ($this->files() as $file) {
            \wp_delete_file($file);
            if (!\file_exists($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Delete sessions older than the retention period.
     *
     * @since 1.0.0
     *
     * @param  int $keepDays Days to keep (default {@see RETENTION_DAYS}).
     * @return int The number of deleted sessions.
     */
    public function pruneExpired(int $keepDays = self::RETENTION_DAYS): int
    {
        $cutoff  = \time() - (\max(1, $keepDays) * 86400);
        $deleted = 0;
        foreach ($this->files() as $file) {
            if ((int) filemtime($file) >= $cutoff) {
                continue;
            }
            \wp_delete_file($file);
            if (!\file_exists($file)) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Drop the oldest session files when the directory exceeds {@see MAX_SESSIONS}.
     *
     * @since 1.0.0
     */
    private function enforceSessionCap(): void
    {
        $files  = $this->files();
        $excess = count($files) - self::MAX_SESSIONS;
        if ($excess <= 0) {
            return;
        }

        usort($files, static fn (string $a, string $b): int => filemtime($a) <=> filemtime($b) ?: strcmp($a, $b));
        foreach (array_slice($files, 0, $excess) as $file) {
            \wp_delete_file($file);
        }
    }

    /**
     * Every session file, in no particular order.
     *
     * @since 1.0.0
     *
     * @return list<string> Absolute paths.
     */
    private function files(): array
    {
        if (!is_dir($this->directory)) {
            return [];
        }

        return array_values(array_filter(
            glob($this->directory . '/*.json') ?: [],
            static fn (string $file): bool => preg_match('/\/\d+-[0-9a-f]{16}\.json$/', $file) === 1
        ));
    }

    /**
     * Decode one session file.
     *
     * @since 1.0.0
     *
     * @param  string $file Absolute path.
     * @return array{email_log_id: int, connection_id: int|null, level: string, line_count: int, lines: array<int, array<string, mixed>>, created_at: string|null}|null
     */
    private function read(string $file): ?array
    {
        if (!is_file($file)) {
            return null;
        }

        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data) || !isset($data['email_log_id'])) {
            return null;
        }

        return [
            'email_log_id'  => (int) $data['email_log_id'],
            'connection_id' => isset($data['connection_id']) ? (int) $data['connection_id'] : null,
            'level'         => (string) ($data['level'] ?? 'info'),
            'line_count'    => (int) ($data['line_count'] ?? 0),
            'lines'         => is_array($data['lines'] ?? null) ? $data['lines'] : [],
            'created_at'    => isset($data['created_at']) ? (string) $data['created_at'] : null,
        ];
    }

    /**
     * The file that holds an email log entry's session: `<id>-<token>.json`.
     *
     * @since 1.0.0
     *
     * @param  int $emailLogId Email log entry.
     * @return string Absolute path.
     */
    private function path(int $emailLogId): string
    {
        return $this->directory . '/' . $emailLogId . '-' . $this->token($emailLogId) . '.json';
    }

    /**
     * The unguessable part of a session file name: the first 16 hex characters of an HMAC of
     * the entry id keyed with the site secret.
     *
     * @since 1.0.0
     *
     * @param  int $emailLogId Email log entry.
     * @return string 16 lowercase hex characters.
     */
    private function token(int $emailLogId): string
    {
        if ($this->resolvedSecret === null) {
            $this->resolvedSecret = $this->secret !== null ? (string) ($this->secret)() : '';
        }

        return substr(hash_hmac('sha256', (string) $emailLogId, $this->resolvedSecret), 0, 16);
    }

    /**
     * Create the sessions directory, shielded from direct requests, when it is missing.
     *
     * Writes an `.htaccess` that denies every request (Apache and LiteSpeed) and an empty
     * `index.php` (no listings); the HMAC file names cover servers that ignore `.htaccess`.
     *
     * @since 1.0.0
     *
     * @return bool True when the directory exists and is writable.
     */
    private function ensureDirectory(): bool
    {
        if (!is_dir($this->directory)) {
            if ($this->prepare !== null && ($this->prepare)() === false) {
                return false;
            }
            $parent = dirname($this->directory);
            if (is_file($parent) || (is_dir($parent) && !\wp_is_writable($parent))) {
                return false;
            }
            \wp_mkdir_p($this->directory);
        }

        if (!is_dir($this->directory) || !\wp_is_writable($this->directory)) {
            return false;
        }

        if (!is_file($this->directory . '/index.php')) {
            file_put_contents($this->directory . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!is_file($this->directory . '/.htaccess')) {
            file_put_contents(
                $this->directory . '/.htaccess',
                "# BooleanSMTP debug sessions: never served directly.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n"
            );
        }

        return true;
    }

    /**
     * The most severe level among the captured lines (`error` > `warning` > `debug` > `info`).
     *
     * @since 1.0.0
     *
     * @param  array<int, array<string, mixed>> $lines Captured lines.
     * @return string
     */
    private function dominantLevel(array $lines): string
    {
        $priority = ['error' => 4, 'warning' => 3, 'debug' => 2, 'info' => 1];
        $max      = 'info';
        $maxP     = 0;

        foreach (array_column($lines, 'level') as $level) {
            $level = is_string($level) ? $level : (string) $level;
            $p     = $priority[$level] ?? 0;
            if ($p > $maxP) {
                $maxP = $p;
                $max  = $level;
            }
        }

        return $max;
    }
}
