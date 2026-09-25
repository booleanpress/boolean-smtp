<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

/**
 * Where a plugin's log files live, how they are named, and what protects them.
 *
 * On WordPress the root is `<uploads>/booleanpress/<slug>/logs`: the one location WordPress
 * guarantees writable, that survives plugin updates, and that follows multisite's per-site
 * uploads. Elsewhere (the framework's own suite, a non-WordPress host) it is the application's
 * `storage/logs`. The `booleanpress_log_path` filter moves it.
 *
 * File names carry a keyed hash — `<channel>[.<n>]-<Y-m-d>-<hash>.log` — because a web server
 * that ignores `.htaccess` (nginx) will serve any file whose name is known; without the site's
 * salt nobody can compute the name.
 */
class LogRoot
{
    /**
     * Files that protect the root and are never pruned.
     */
    public const PROTECTION_FILES = ['.htaccess', 'index.php', 'web.config'];

    /**
     * Channel file names: channel, optional rotation, date, hash.
     */
    public const FILE_PATTERN = '/^(?<channel>[a-z0-9_-]+)(?:\.(?<n>\d+))?-(?<date>\d{4}-\d{2}-\d{2})-(?<hash>[0-9a-f]{10})\.log$/';

    public function __construct(
        protected string $path,
        protected string $slug,
        protected string $salt,
    ) {
        $this->path = rtrim($path, '/\\');
    }

    /**
     * Resolve the root for a plugin.
     *
     * @param string   $slug     The plugin's slug.
     * @param string   $fallback Directory used when WordPress's uploads directory is unavailable.
     * @param callable $filter   `apply_filters`-shaped callable.
     */
    public static function resolve(string $slug, string $fallback, callable $filter): self
    {
        $path = $fallback;

        if (\function_exists('wp_upload_dir')) {
            $uploads = \wp_upload_dir(null, false);
            $base = \is_array($uploads) ? (string) ($uploads['basedir'] ?? '') : '';
            if ($base !== '') {
                $path = rtrim($base, '/\\') . '/booleanpress/' . $slug . '/logs';
            }
        }

        /**
         * Filters the directory a plugin's log files are written to.
         *
         * Defaults to `<uploads>/booleanpress/<slug>/logs`. A site that offloads uploads, or wants
         * the logs outside the web root, returns another absolute path here.
         *
         * @param string $path The log directory, without a trailing slash.
         * @param string $slug The plugin's slug.
         */
        $path = (string) $filter('booleanpress_log_path', $path, $slug);

        return new self($path, $slug, self::salt($path));
    }

    /**
     * The key file names are hashed with: the site's `AUTH_SALT`, WordPress's auth salt, or —
     * outside WordPress — a value derived from the path.
     */
    protected static function salt(string $path): string
    {
        // WordPress's sample configuration ships this phrase; a site that kept it has no secret.
        $placeholder = 'put your unique phrase here';
        if (\defined('AUTH_SALT') && \is_string(\constant('AUTH_SALT')) && \constant('AUTH_SALT') !== '' && \constant('AUTH_SALT') !== $placeholder) {
            return (string) \constant('AUTH_SALT');
        }
        if (\function_exists('wp_salt')) {
            // Generates and stores a random salt when the constant is missing or the placeholder.
            return (string) \wp_salt('auth');
        }

        return hash('sha256', __FILE__ . '|' . $path);
    }

    /**
     * The root directory, without a trailing slash.
     */
    public function path(string $relative = ''): string
    {
        return $this->path . ($relative !== '' ? '/' . ltrim($relative, '/') : '');
    }

    /**
     * The file name of a channel's log for a day and a rotation number (0 = the first file).
     */
    public function fileName(string $channel, string $date, int $rotation = 0): string
    {
        $hash = substr(hash_hmac('sha256', "{$this->slug}|{$channel}|{$date}|{$rotation}", $this->salt), 0, 10);

        return $channel . ($rotation > 0 ? '.' . $rotation : '') . '-' . $date . '-' . $hash . '.log';
    }

    /**
     * Parse a channel file name.
     *
     * @return array{channel: string, rotation: int, date: string}|null Null for any other file.
     */
    public function parse(string $fileName): ?array
    {
        if (!preg_match(self::FILE_PATTERN, $fileName, $m)) {
            return null;
        }

        return ['channel' => $m['channel'], 'rotation' => (int) $m['n'], 'date' => $m['date']];
    }

    /**
     * Create the root and its protection files when missing.
     *
     * Protection files go only into a directory this method created, or one that already carries
     * them (recognised by the signature line): a root moved onto an existing shared folder —
     * `wp-content`, say — must never receive a deny-all `.htaccess` that would lock the site's
     * assets away on Apache.
     *
     * @return bool Whether the root exists and is writable.
     */
    public function ensure(): bool
    {
        if (is_link($this->path)) {
            return false;
        }

        $created = false;
        if (!is_dir($this->path)) {
            $created = \function_exists('wp_mkdir_p')
                ? \wp_mkdir_p($this->path)
                : @mkdir($this->path, 0750, true); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir -- outside WordPress only.
            if (!$created && !is_dir($this->path)) {
                return false;
            }
            @chmod($this->path, 0750); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- the log root is not group- or world-writable.
            $created = true;
        }

        if (!is_writable($this->path)) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable -- direct filesystem check on the log root.
            return false;
        }

        if (!$created && !$this->isOwnRoot()) {
            return true;
        }

        foreach (self::protectionFiles() as $name => $contents) {
            $file = $this->path . '/' . $name;
            if (!is_file($file)) {
                @file_put_contents($file, $contents); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- protection files are written once, on creation.
            }
        }

        return true;
    }

    /**
     * Whether the directory already carries this framework's protection files.
     */
    public function isOwnRoot(): bool
    {
        // Only the .htaccess carries a signature no other software writes; a stock index.php does not.
        $file = $this->path . '/.htaccess';

        return is_file($file) && (string) @file_get_contents($file) === self::protectionFiles()['.htaccess']; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- a local signature check.
    }

    /**
     * The protection files and their contents.
     *
     * @return array<string, string>
     */
    public static function protectionFiles(): array
    {
        return [
            '.htaccess' => "# Written by BooleanPress Core: log files are not served.\n"
                . "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n"
                . "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n",
            'index.php' => "<?php\n// BooleanPress Core log directory: nothing is served from here.\n",
            'web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration>\n  <system.webServer>\n"
                . "    <authorization>\n      <deny users=\"*\" />\n    </authorization>\n  </system.webServer>\n</configuration>\n",
        ];
    }
}
