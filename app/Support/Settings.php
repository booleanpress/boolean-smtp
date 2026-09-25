<?php
/**
 * The plugin's general settings: a typed facade over the framework's settings store.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

/**
 * Reads and writes plugin settings under the `boolean-smtp.*` key prefix, backed by the
 * framework's {@see \BooleanSmtp\Core\Settings\SettingsRepository} (the one store; this class adds
 * the defaults, the key prefix and a per-request cache). Bound as a singleton, so every
 * consumer in a request shares the cache.
 *
 * Every key listed in {@see $defaults} is served from a single cached read for the rest
 * of the request (one preload query for all of them); keys not listed there are passed
 * straight through to the underlying settings repository, which remembers absent keys.
 *
 * An add-on keeps its own settings by extending this class with its own {@see $prefix}
 * and {@see $defaults} and a store scoped to its own plugin slug.
 *
 * @since 1.0.0
 */
class Settings {
    /**
     * Prefix every key is stored under, plugin slug plus a dot.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $prefix = 'boolean-smtp.';

    /**
     * Default values for every known setting key.
     *
     * Keys: `from_name`, `from_email` (default sender identity); `force_from` (the global
     * identity overrides the one a connection or a plugin set); `simulation_enabled` (Email
     * Simulation Mode, no real delivery); `show_test_email_console` (show the raw test-email
     * console in the admin UI); `auto_plain_text` (auto-generate a plain-text part); `log_emails`,
     * `log_mailer_diagnostics`, `log_body` (email logging toggles; the body switch is the add-on's
     * control); `log_retention_days` (days to keep logs, default 30); `fallback_enabled`,
     * `default_connection_id`, `fallback_connection_id` (connection routing); `auto_retry` (retry a
     * failed message on the other active connections); `health_check_enabled`,
     * `health_check_interval` (minutes, default 15); `oauth_refresh_enabled`, `oauth_refresh_interval`
     * (minutes, default 15), `oauth_refresh_max_retries` (attempts per token, default 3);
     * `encryption_key` (at-rest key generated for the site when no constant defines one);
     * `delete_data_on_uninstall` (deleting the plugin also deletes its data, see
     * {@see UninstallPolicy}; off by default).
     *
     * @since 1.0.0
     * @var array<string, mixed>
     */
    protected array $defaults = [
        'from_name'                  => '',
        'from_email'                 => '',
        'force_from'                 => false,
        'simulation_enabled'         => false,
        'show_test_email_console'    => false,
        'auto_plain_text'            => true,
        'log_emails'                 => true,
        'log_mailer_diagnostics'     => false,
        'log_body'                   => true,
        'log_retention_days'         => 30,
        'fallback_enabled'           => true,
        'default_connection_id'      => null,
        'fallback_connection_id'     => null,
        'auto_retry'                 => true,
        'health_check_enabled'       => true,
        'health_check_interval'      => 15,
        'oauth_refresh_enabled'      => true,
        'oauth_refresh_interval'     => 15,
        'oauth_refresh_max_retries'  => 3,
        'encryption_key'             => '',
        'delete_data_on_uninstall'   => false,
    ];

    /**
     * Per-request cache of {@see all()}, invalidated on a successful {@see set()}.
     *
     * @since 1.0.0
     * @var array<string, mixed>|null
     */
    private ?array $cached = null;

    /**
     * @since 1.0.0
     *
     * @param \BooleanSmtp\Core\Settings\SettingsRepository $store The framework store, scoped to this plugin.
     */
    public function __construct(private readonly \BooleanSmtp\Core\Settings\SettingsRepository $store) {
    }

    /**
     * Get every known setting, merging stored values over the defaults.
     *
     * Cached for the remainder of the request after the first call.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed> Every key from {@see $defaults}, with stored overrides applied.
     */
    public function all(): array {
        if ($this->cached !== null) {
            return $this->cached;
        }

        $keys = array_map(fn (string $key): string => $this->prefix . $key, array_keys($this->defaults));
        $this->store->preload($keys);

        $stored = [];
        foreach ($this->defaults as $key => $default) {
            $stored[$key] = $this->store->get($this->prefix . $key, $default);
        }

        $this->cached = $stored;
        return $stored;
    }

    /**
     * Get a single setting value.
     *
     * Known keys (present in {@see $defaults}) are served from the cached {@see all()}
     * result; any other key is read directly from the underlying settings repository.
     *
     * @since 1.0.0
     *
     * @param  string $key     Setting key, without the prefix.
     * @param  mixed  $default Value to return when the key has no stored or default value.
     * @return mixed The setting value.
     */
    public function get(string $key, mixed $default = null): mixed {
        if (array_key_exists($key, $this->defaults)) {
            $all = $this->all();
            return $all[$key] ?? $default ?? $this->defaults[$key];
        }

        return $this->store->get($this->prefix . $key, $default);
    }

    /**
     * Set a single setting value.
     *
     * Invalidates the cached {@see all()} result on success so the next read reflects
     * the new value.
     *
     * @since 1.0.0
     *
     * @param  string $key   Setting key, without the prefix.
     * @param  mixed  $value New value to store.
     * @return bool True when the value was stored.
     */
    public function set(string $key, mixed $value): bool {
        $result = $this->store->set($this->prefix . $key, $value);
        if ($result) {
            $this->cached = null;
        }
        return $result;
    }

    /**
     * Update multiple settings at once.
     *
     * Only keys present in {@see $defaults} are written; unknown keys in `$data` are
     * silently ignored. Stops and returns false on the first write failure, leaving any
     * settings already written in place.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Setting values keyed by setting key.
     * @return bool True when every known key was stored successfully.
     */
    public function update(array $data): bool {
        foreach ($data as $key => $value) {
            if (array_key_exists($key, $this->defaults)) {
                if (!$this->set($key, $value)) {
                    return false;
                }
            }
        }
        return true;
    }

    /**
     * The key prefix this facade stores under (plugin slug plus a dot).
     *
     * @since 1.0.0
     *
     * @return string
     */
    public function prefix(): string {
        return $this->prefix;
    }
}
