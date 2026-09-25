<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

/**
 * Appends to channel files and keeps the log root bounded.
 *
 * - One file per channel per UTC day. When the day's file would pass the size limit the channel
 *   moves to `.1`, `.2` … up to the rotation limit, then wraps around and reuses (truncates) the
 *   oldest rotation of the day, so the newest entries are always kept and a single day can never
 *   exceed `(rotations + 1) × size`.
 * - Every time a channel starts a new file (a new day or a rotation) the whole root is pruned:
 *   files past their channel's retention, then the oldest files until the root is under its size
 *   ceiling. This needs no scheduler; a daily job may call {@see prune()} as well.
 * - Appends hold an exclusive lock. A write that fails is reported once through `error_log()` and
 *   the channel stops writing for the rest of the request, so a full disk costs one line per
 *   request, not one per entry.
 */
class FileWriter
{
    /**
     * Mode for new log files: owner read/write, group read, nothing for others.
     */
    public const FILE_MODE = 0640;

    /**
     * Per-channel state for this request: the day and the current rotation.
     *
     * @var array<string, array{date: string, rotation: int}>
     */
    protected array $state = [];

    /**
     * Channels that failed to write in this request.
     *
     * @var array<string, true>
     */
    protected array $failed = [];

    /**
     * @param \Closure(): int                  $clock    Current Unix time.
     * @param \Closure(string): void           $errorLog Where failures are reported.
     */
    public function __construct(
        protected LogRoot $root,
        protected LogManager $manager,
        protected \Closure $clock,
        protected \Closure $errorLog,
    ) {
    }

    /**
     * Append text (one or more complete lines) to a channel's current file.
     */
    public function append(string $channel, string $text): void
    {
        if (isset($this->failed[$channel]) || $text === '') {
            return;
        }
        if (!$this->root->ensure()) {
            $this->fail($channel, 'the log directory ' . $this->root->path() . ' cannot be created or is not writable');
            return;
        }

        $date = gmdate('Y-m-d', ($this->clock)());
        $rotation = ($this->state[$channel]['date'] ?? null) === $date
            ? $this->state[$channel]['rotation']
            : $this->current($channel, $date);

        $maxSize = $this->manager->maxFileSize($channel);
        $maxRotations = $this->manager->maxRotations($channel);
        $rotation = min($rotation, $maxRotations);

        // The size that decides a rotation is read under the lock, so another process's writes count.
        $path = $this->root->path($this->root->fileName($channel, $date, $rotation));
        $result = $this->write($path, $text, $maxSize, false);

        if ($result === 'full') {
            $rotation = ($rotation + 1) % ($maxRotations + 1);
            $path = $this->root->path($this->root->fileName($channel, $date, $rotation));
            // A full file in the next slot is a stale rotation (reused, truncated); a partly filled one
            // was just started by another process and is appended to.
            $result = $this->write($path, $text, $maxSize, true);
        }

        if ($result === 'failed') {
            $this->fail($channel, 'cannot write ' . $path);
            return;
        }

        $this->state[$channel] = ['date' => $date, 'rotation' => $rotation];

        if ($result === 'new') {
            $this->prune();
        }
    }

    /**
     * Delete files past their channel's retention, then the oldest files until the root is under
     * its size ceiling. Protection files are never touched.
     *
     * @return int Files removed.
     */
    public function prune(): int
    {
        $dir = $this->root->path();
        if (!is_dir($dir)) {
            return 0;
        }

        $now = ($this->clock)();
        $removed = 0;
        $files = [];

        foreach ($this->files($dir) as $path) {
            $parsed = $this->root->parse(basename($path));
            if ($parsed !== null && \dirname($path) === $dir) {
                $days = $this->manager->retentionDays($parsed['channel']);
                $cutoff = gmdate('Y-m-d', $now - ($days - 1) * 86400);
                if ($parsed['date'] < $cutoff && $this->delete($path)) {
                    $removed++;
                    continue;
                }
            }
            $files[$path] = [(int) @filemtime($path), (int) @filesize($path)];
        }

        $total = array_sum(array_column($files, 1));
        $ceiling = $this->manager->maxTotalSize();
        if ($total > $ceiling) {
            uasort($files, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            foreach ($files as $path => [, $size]) {
                if ($total <= $ceiling) {
                    break;
                }
                if ($this->delete($path)) {
                    $total -= $size;
                    $removed++;
                }
            }
        }

        if ($removed > 0) {
            clearstatcache();
            $this->state = [];
        }

        return $removed;
    }

    /**
     * The rotation to continue today: the most recently written one. File times have a resolution
     * of one second, so on a tie the less full file wins — in a ring every file but the current one
     * has been filled to the limit.
     */
    protected function current(string $channel, string $date): int
    {
        $best = 0;
        $bestKey = null;

        for ($n = 0; $n <= $this->manager->maxRotations($channel); $n++) {
            $path = $this->root->path($this->root->fileName($channel, $date, $n));
            if (!is_file($path)) {
                continue;
            }
            clearstatcache(true, $path);
            $key = [(int) @filemtime($path), -(int) @filesize($path)];
            if ($bestKey === null || $key > $bestKey) {
                $bestKey = $key;
                $best = $n;
            }
        }

        return $best;
    }

    /**
     * Append under an exclusive lock.
     *
     * Returns `full` (nothing written: the file would pass the size limit), `new` (written, the
     * file is new or was reused), `appended`, or `failed`. With `$mayReuse`, a file that is full
     * is truncated and reused instead of reported full.
     */
    protected function write(string $path, string $text, int $maxSize, bool $mayReuse): string
    {
        $existed = is_file($path);
        // phpcs:disable WordPress.WP.AlternativeFunctions -- WP_Filesystem has no locked append and may be FTP-backed; log writes go straight to the local disk.
        $handle = @fopen($path, 'cb');
        if ($handle === false) {
            return 'failed';
        }

        $result = 'failed';
        if (flock($handle, LOCK_EX)) {
            $stat = fstat($handle);
            $size = \is_array($stat) ? (int) $stat['size'] : 0;
            $reused = false;

            if ($size > 0 && $size + \strlen($text) > $maxSize) {
                if ($mayReuse) {
                    ftruncate($handle, 0);
                    $size = 0;
                    $reused = true;
                } else {
                    $result = 'full';
                }
            }

            if ($result !== 'full') {
                fseek($handle, $size);
                $written = fwrite($handle, $text) === \strlen($text);
                fflush($handle);
                $result = !$written ? 'failed' : (($reused || !$existed || $size === 0) ? 'new' : 'appended');
            }
            flock($handle, LOCK_UN);
        }
        fclose($handle);
        // phpcs:enable

        if (!$existed) {
            @chmod($path, self::FILE_MODE); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- log files are not group-writable or world-readable.
        }

        return $result;
    }

    /**
     * The files the manager owns: channel files in the root, and files in a subdirectory the
     * plugin registered with {@see LogManager::manage()} whose names match its pattern. Anything
     * else in the directory — a root that was pointed at a shared folder, a file a person left
     * there — is never counted or deleted.
     *
     * @return list<string>
     */
    protected function files(string $dir): array
    {
        $out = [];
        foreach ((array) @scandir($dir) as $name) {
            if (!\is_string($name) || $this->root->parse($name) === null) {
                continue;
            }
            $path = $dir . '/' . $name;
            if (is_file($path) && !is_link($path)) {
                $out[] = $path;
            }
        }

        foreach ($this->manager->managedDirectories() as $subdir => $pattern) {
            $sub = $dir . '/' . $subdir;
            if (!is_dir($sub) || is_link($sub)) {
                continue;
            }
            foreach ((array) @scandir($sub) as $name) {
                if (\is_string($name) && preg_match($pattern, $name) && is_file($sub . '/' . $name) && !is_link($sub . '/' . $name)) {
                    $out[] = $sub . '/' . $name;
                }
            }
        }

        return $out;
    }

    /**
     * Delete a file; another process removing it first is not an error.
     */
    protected function delete(string $path): bool
    {
        if (\function_exists('wp_delete_file')) {
            \wp_delete_file($path);
        } else {
            @unlink($path); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink -- outside WordPress only.
        }

        return !is_file($path);
    }

    /**
     * Report a failed write once and stop the channel for this request.
     */
    protected function fail(string $channel, string $reason): void
    {
        $this->failed[$channel] = true;
        ($this->errorLog)('[booleanpress-log] ' . $channel . ': ' . $reason);
    }
}
