<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

use BooleanSmtp\Core\Foundation\Application;

/**
 * A plugin's log channels: which are on, at what level, and how long and how large they may grow.
 *
 * **Nothing is written unless a filter switches a channel on** (`booleanpress_log_enabled`). Every
 * limit is a filter too, each receiving the plugin's slug as its last argument so one plugin's
 * settings never leak into another's. Channel names are lowercase letters, digits, `-` and `_`.
 */
class LogManager
{
    public const DEFAULT_LEVEL = 'warning';
    public const DEFAULT_RETENTION_DAYS = 14;
    public const DEFAULT_MAX_FILE_SIZE = 5 * 1024 * 1024;
    public const DEFAULT_MAX_ROTATIONS = 5;
    public const DEFAULT_MAX_TOTAL_SIZE = 50 * 1024 * 1024;
    public const MAX_ROTATIONS_LIMIT = 100;

    /**
     * `apply_filters`-shaped callable.
     *
     * @var \Closure(string, mixed, mixed...): mixed
     */
    protected \Closure $filter;

    /**
     * @var \Closure(): int
     */
    protected \Closure $clock;

    protected FileWriter $writer;

    /**
     * @var array<string, Channel>
     */
    protected array $channels = [];

    /**
     * Subdirectories of the root whose matching files the manager may prune: name => regex.
     *
     * @var array<string, string>
     */
    protected array $managed = [];

    /**
     * @param string                              $slug     The plugin's slug.
     * @param LogRoot                             $root     Where the files go.
     * @param (callable(string, mixed, mixed...): mixed)|null $filter `apply_filters` by default.
     * @param (callable(): int)|null              $clock    `time()` by default.
     * @param (callable(string): mixed)|null      $errorLog `error_log()` by default.
     */
    public function __construct(
        protected string $slug,
        protected LogRoot $root,
        ?callable $filter = null,
        ?callable $clock = null,
        ?callable $errorLog = null,
    ) {
        $this->filter = $filter !== null
            ? \Closure::fromCallable($filter)
            : static fn (string $tag, mixed $value, mixed ...$args): mixed => \function_exists('apply_filters') ? \apply_filters($tag, $value, ...$args) : $value;
        $this->clock = $clock !== null ? \Closure::fromCallable($clock) : static fn (): int => time();
        $errorLog = $errorLog !== null
            ? \Closure::fromCallable($errorLog)
            : static function (string $message): void {
                error_log($message); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- a log write failed; PHP's own log is the only place left to say so.
            };
        $this->writer = new FileWriter($root, $this, $this->clock, static function (string $message) use ($errorLog): void {
            $errorLog($message);
        });
    }

    /**
     * The manager for an application: its slug, the WordPress root (or `storage/logs`).
     */
    public static function fromApplication(Application $app): self
    {
        $filter = static fn (string $tag, mixed $value, mixed ...$args): mixed => \function_exists('apply_filters') ? \apply_filters($tag, $value, ...$args) : $value;
        $slug = $app->pluginSlug();

        return new self($slug, LogRoot::resolve($slug, $app->storagePath('logs'), $filter), $filter);
    }

    /**
     * A channel's logger.
     */
    public function channel(string $name): Channel
    {
        $name = self::normalise($name);

        return $this->channels[$name] ??= new Channel($this, $name);
    }

    /**
     * Let the manager prune a subdirectory of the root that another writer fills (a plugin's
     * debug-session files, say): files whose names match `$pattern` count toward the size ceiling
     * and are deleted oldest-first with the channel files. Nothing else in the root is ever touched.
     *
     * @param string $subdirectory A direct child of the root, for example `debug-sessions`.
     * @param string $pattern      A PCRE the file names match.
     */
    public function manage(string $subdirectory, string $pattern): static
    {
        $subdirectory = trim($subdirectory, '/\\');
        if ($subdirectory !== '' && !str_contains($subdirectory, '..') && @preg_match($pattern, '') !== false) {
            $this->managed[$subdirectory] = $pattern;
        }

        return $this;
    }

    /**
     * The subdirectories registered with {@see manage()}.
     *
     * @return array<string, string>
     */
    public function managedDirectories(): array
    {
        return $this->managed;
    }

    /**
     * The plugin's slug.
     */
    public function slug(): string
    {
        return $this->slug;
    }

    /**
     * The log root.
     */
    public function root(): LogRoot
    {
        return $this->root;
    }

    /**
     * Whether a channel writes at all. Off unless a filter says otherwise.
     */
    public function enabled(string $channel): bool
    {
        /**
         * Filters whether a log channel writes to disk. Every channel is off by default.
         *
         * @param bool   $enabled Whether the channel writes. Default false.
         * @param string $channel The channel name (for example `app`, `core`, `requests`).
         * @param string $slug    The plugin's slug.
         */
        return (bool) $this->apply('booleanpress_log_enabled', false, self::normalise($channel), $this->slug);
    }

    /**
     * The least severe PSR-3 level a channel writes.
     */
    public function level(string $channel): string
    {
        /**
         * Filters the least severe level a log channel writes.
         *
         * @param string $level   A PSR-3 level name. Default `warning`.
         * @param string $channel The channel name.
         * @param string $slug    The plugin's slug.
         */
        $level = strtolower((string) $this->apply('booleanpress_log_level', self::DEFAULT_LEVEL, $channel, $this->slug));

        return \in_array($level, Formatter::LEVELS, true) ? $level : self::DEFAULT_LEVEL;
    }

    /**
     * Days a channel's files are kept (at least 1).
     */
    public function retentionDays(string $channel): int
    {
        /**
         * Filters how many days of a log channel's files are kept.
         *
         * There is no "forever": values below 1 are treated as 1.
         *
         * @param int    $days    Days to keep, today included. Default 14.
         * @param string $channel The channel name.
         * @param string $slug    The plugin's slug.
         */
        return max(1, (int) $this->apply('booleanpress_log_retention_days', self::DEFAULT_RETENTION_DAYS, $channel, $this->slug));
    }

    /**
     * Bytes after which a channel's file rotates (at least 1 KB).
     */
    public function maxFileSize(string $channel): int
    {
        /**
         * Filters the size at which a log channel's file rotates to the next one.
         *
         * @param int    $bytes   Default 5 MB; values below 1 KB are treated as 1 KB.
         * @param string $channel The channel name.
         * @param string $slug    The plugin's slug.
         */
        return max(1024, (int) $this->apply('booleanpress_log_max_file_size', self::DEFAULT_MAX_FILE_SIZE, $channel, $this->slug));
    }

    /**
     * Rotations a channel keeps per day before reusing the oldest (at least 1).
     */
    public function maxRotations(string $channel): int
    {
        /**
         * Filters how many size rotations a log channel keeps per day before it reuses the oldest.
         *
         * @param int    $count   Default 5; clamped to 1–100.
         * @param string $channel The channel name.
         * @param string $slug    The plugin's slug.
         */
        return max(1, min(self::MAX_ROTATIONS_LIMIT, (int) $this->apply('booleanpress_log_max_rotations', self::DEFAULT_MAX_ROTATIONS, $channel, $this->slug)));
    }

    /**
     * The ceiling for everything under the log root (at least 64 KB).
     */
    public function maxTotalSize(): int
    {
        /**
         * Filters the most the whole log directory may hold; the oldest files go first.
         *
         * @param int    $bytes Default 50 MB; values below 64 KB are treated as 64 KB.
         * @param string $slug  The plugin's slug.
         */
        return max(65536, (int) $this->apply('booleanpress_log_max_total_size', self::DEFAULT_MAX_TOTAL_SIZE, $this->slug));
    }

    /**
     * Write one entry to a channel, if it is on and the level passes.
     *
     * @param array<mixed> $context
     */
    public function write(string $channel, string $level, string $message, array $context = []): void
    {
        try {
            $channel = self::normalise($channel);
            if (!$this->enabled($channel) || !Formatter::passes($level, $this->level($channel))) {
                return;
            }

            $context = Formatter::redact($context);
            /**
             * Filters a log entry's context after the default masking, before it is written.
             *
             * @param array<mixed> $context The entry's context.
             * @param string       $channel The channel name.
             * @param string       $slug    The plugin's slug.
             */
            $context = (array) $this->apply('booleanpress_log_context', $context, $channel, $this->slug);

            $this->writer->append($channel, Formatter::line($level, $message, $context, ($this->clock)()));
        } catch (\Throwable) {
            // intentionally silent: logging never breaks the code that logs.
        }
    }

    /**
     * Append a preformatted block to a channel, if it is on — for developer channels that write
     * multi-line diagnostics. The block is written as given; the level filter does not apply.
     */
    public function append(string $channel, string $block): void
    {
        try {
            $channel = self::normalise($channel);
            if (!$this->enabled($channel)) {
                return;
            }
            if (!str_ends_with($block, "\n")) {
                $block .= "\n";
            }

            $this->writer->append($channel, $block);
        } catch (\Throwable) {
            // intentionally silent: logging never breaks the code that logs.
        }
    }

    /**
     * Apply retention and the size ceiling now.
     *
     * @return int Files removed.
     */
    public function prune(): int
    {
        try {
            return $this->writer->prune();
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Run a filter through the injected callable.
     */
    protected function apply(string $tag, mixed $value, mixed ...$args): mixed
    {
        return ($this->filter)($tag, $value, ...$args);
    }

    /**
     * A safe channel name: lowercase letters, digits, `-` and `_`.
     */
    public static function normalise(string $channel): string
    {
        $channel = strtolower((string) preg_replace('/[^a-z0-9_-]+/i', '-', $channel));
        $channel = trim($channel, '-');

        return $channel !== '' ? $channel : 'app';
    }
}
