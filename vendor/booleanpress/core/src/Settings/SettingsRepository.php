<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Settings;

use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use function BooleanSmtp\Core\app;

/**
 * Settings Repository
 *
 * Multi-purpose data store backed by the shared `booleanpress_options` table.
 * Supports settings, entities, logs, cache and transients -- all scoped by
 * plugin slug. The `type` column categorises rows; `ref_id` allows multiple
 * rows per option_name (e.g. one log blob per connection); `expires_at`
 * enables automatic pruning for transient/log data.
 */
class SettingsRepository
{
    protected string $plugin;

    protected DriverInterface $driver;

    protected string $tableName;

    /** @var array<string, mixed>|null */
    protected ?array $cache = null;

    /**
     * Setting keys this request already looked for and found absent, so a second read
     * costs nothing. Cleared for a key when it is written or deleted.
     *
     * @since 0.2.2
     * @var array<string, true>
     */
    protected array $missing = [];

    /**
     * True once the options table was seen this request; the check is never repeated
     * after that (a table that appears later is still found, one that vanishes is not).
     *
     * @since 0.2.2
     */
    protected bool $tableSeen = false;

    public function __construct(string $plugin)
    {
        $this->plugin = $plugin;
        $this->driver = app(DriverInterface::class);
        $this->tableName = $this->driver->getTable('booleanpress_options');
    }

    // ---------------------------------------------------------------
    //  Settings (type = 'setting', ref_id = 0)
    // ---------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        if ($this->cache !== null && array_key_exists($key, $this->cache)) {
            return $this->cache[$key];
        }

        if (isset($this->missing[$key]) || !$this->tableExists()) {
            return $default;
        }

        $value = $this->driver->selectVar(
            "SELECT option_value FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0",
            [$this->plugin, $key]
        );

        if ($value === null) {
            $this->missing[$key] = true;

            return $default;
        }

        $value = $this->maybeUnserialize($value);
        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }

        return $value;
    }

    /**
     * Fetch several settings in one query so the following {@see get()} calls are served
     * from memory: keys already cached or known to be absent are skipped, the rest are
     * read with one `IN (…)` query, and the ones the table does not hold are remembered as
     * absent.
     *
     * @since 0.2.2
     *
     * @param list<string> $keys Setting keys (`ref_id` 0).
     */
    public function preload(array $keys): void
    {
        $this->cache ??= [];

        $wanted = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $this->cache) && !isset($this->missing[$key])) {
                $wanted[$key] = true;
            }
        }

        if ($wanted === [] || !$this->tableExists()) {
            return;
        }

        $names        = array_keys($wanted);
        $placeholders = implode(', ', array_fill(0, count($names), '%s'));
        $rows         = $this->driver->select(
            "SELECT option_name, option_value FROM {$this->tableName} WHERE plugin = %s AND ref_id = 0 AND option_name IN ({$placeholders})",
            array_merge([$this->plugin], $names)
        );

        foreach ($rows ?: [] as $row) {
            $this->cache[$row['option_name']] = $this->maybeUnserialize($row['option_value']);
            unset($wanted[$row['option_name']]);
        }

        foreach (array_keys($wanted) as $absent) {
            $this->missing[$absent] = true;
        }
    }

    public function set(string $key, mixed $value, string $autoload = 'yes'): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $serialized = is_scalar($value) || is_null($value) ? $value : serialize($value);

        $exists = $this->driver->selectVar(
            "SELECT id FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0",
            [$this->plugin, $key]
        );

        if ($exists) {
            $result = $this->driver->update(
                'booleanpress_options',
                ['option_value' => $serialized, 'autoload' => $autoload],
                ['plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => 0]
            );
        } else {
            $result = $this->driver->insert('booleanpress_options', [
                'plugin'       => $this->plugin,
                'version'      => '1.0.0',
                'option_name'  => $key,
                'option_value' => $serialized,
                'autoload'     => $autoload,
                'type'         => 'setting',
                'ref_id'       => 0,
            ]);
        }

        if ($this->cache !== null) {
            $this->cache[$key] = $value;
        }
        unset($this->missing[$key]);

        return $result !== false;
    }

    public function delete(string $key): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $result = $this->driver->delete('booleanpress_options', [
            'plugin'      => $this->plugin,
            'option_name' => $key,
            'ref_id'      => 0,
        ]);

        if ($this->cache !== null) {
            unset($this->cache[$key]);
        }
        unset($this->missing[$key]);

        return $result !== false;
    }

    /** @return array<string, mixed> */
    public function all(Schema $schema): array
    {
        $settings = [];
        foreach ($schema->getFields() as $key => $field) {
            $fieldArray     = $field->toArray();
            $default        = $fieldArray['default'] ?? null;
            $settings[$key] = $this->get($key, $default);
        }
        return $settings;
    }

    public function loadAutoloaded(): void
    {
        if (!$this->tableExists()) {
            $this->cache = [];
            return;
        }

        $results = $this->driver->select(
            "SELECT option_name, option_value FROM {$this->tableName} WHERE plugin = %s AND autoload = %s AND ref_id = 0",
            [$this->plugin, 'yes']
        );

        $this->cache = [];
        if ($results) {
            foreach ($results as $row) {
                $this->cache[$row['option_name']] = $this->maybeUnserialize($row['option_value']);
            }
        }
    }

    public function getFrom(string $plugin, string $key, mixed $default = null): mixed
    {
        if (!$this->tableExists()) {
            return $default;
        }

        $value = $this->driver->selectVar(
            "SELECT option_value FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0",
            [$plugin, $key]
        );

        return $value !== null ? $this->maybeUnserialize($value) : $default;
    }

    // ---------------------------------------------------------------
    //  Ref-scoped data (entity / log rows with ref_id > 0)
    // ---------------------------------------------------------------

    /**
     * Store a value keyed by (option_name, ref_id). Upserts.
     *
     * The options table is the ecosystem's central store for a plugin's settings, transients
     * and *small, bounded* datasets (an entity list, a capped per-record log); anything large
     * or queried by column gets a table and a Model.
     */
    public function setWithRef(string $key, int $refId, mixed $value, string $type = 'log', ?string $expiresAt = null): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $serialized = is_scalar($value) || is_null($value) ? $value : serialize($value);

        $exists = $this->driver->selectVar(
            "SELECT id FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = %d",
            [$this->plugin, $key, $refId]
        );

        $data = [
            'option_value' => $serialized,
            'type'         => $type,
            'expires_at'   => $expiresAt,
        ];

        if ($exists) {
            $result = $this->driver->update(
                'booleanpress_options',
                $data,
                ['plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => $refId]
            );
        } else {
            $result = $this->driver->insert('booleanpress_options', array_merge($data, [
                'plugin'      => $this->plugin,
                'version'     => '1.0.0',
                'option_name' => $key,
                'ref_id'      => $refId,
                'autoload'    => 'no',
            ]));
        }

        if ($refId === 0) {
            $this->forget($key);
        }

        return $result !== false;
    }

    /**
     * Retrieve a ref-scoped value.
     *
     */
    public function getByRef(string $key, int $refId, mixed $default = null): mixed
    {
        if (!$this->tableExists()) {
            return $default;
        }

        $value = $this->driver->selectVar(
            "SELECT option_value FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = %d",
            [$this->plugin, $key, $refId]
        );

        return $value !== null ? $this->maybeUnserialize($value) : $default;
    }

    /**
     * Delete a ref-scoped value.
     *
     */
    public function deleteByRef(string $key, int $refId): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        if ($refId === 0) {
            $this->forget($key);
        }

        return $this->driver->delete('booleanpress_options', [
            'plugin'      => $this->plugin,
            'option_name' => $key,
            'ref_id'      => $refId,
        ]) !== false;
    }

    // ---------------------------------------------------------------
    //  Entity helpers (type = 'entity', single JSON blob, ref_id = 0)
    // ---------------------------------------------------------------

    /**
     * Store a small entity list as one blob (e.g. a plugin's notification channels).
     */
    public function setEntity(string $key, mixed $value): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $serialized = is_scalar($value) || is_null($value) ? $value : serialize($value);

        $exists = $this->driver->selectVar(
            "SELECT id FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0",
            [$this->plugin, $key]
        );

        $data = [
            'option_value' => $serialized,
            'type'         => 'entity',
            'autoload'     => 'no',
        ];

        if ($exists) {
            $result = $this->driver->update(
                'booleanpress_options',
                $data,
                ['plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => 0]
            );
        } else {
            $result = $this->driver->insert('booleanpress_options', array_merge($data, [
                'plugin'      => $this->plugin,
                'version'     => '1.0.0',
                'option_name' => $key,
                'ref_id'      => 0,
            ]));
        }

        $this->forget($key);

        return $result !== false;
    }

    // ---------------------------------------------------------------
    //  Query by type
    // ---------------------------------------------------------------

    /**
     * Get all rows matching a type and option_name.
     *
     *
     * @return array<array<string, mixed>>
     */
    public function getByType(string $type, ?string $optionName = null): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        if ($optionName !== null) {
            $rows = $this->driver->select(
                "SELECT * FROM {$this->tableName} WHERE plugin = %s AND type = %s AND option_name = %s ORDER BY ref_id ASC",
                [$this->plugin, $type, $optionName]
            );
        } else {
            $rows = $this->driver->select(
                "SELECT * FROM {$this->tableName} WHERE plugin = %s AND type = %s ORDER BY option_name, ref_id ASC",
                [$this->plugin, $type]
            );
        }

        if (!$rows) {
            return [];
        }

        return array_map(function (array $row): array {
            $row['option_value'] = $this->maybeUnserialize($row['option_value']);
            return $row;
        }, $rows);
    }

    // ---------------------------------------------------------------
    //  Transients (type = 'transient', auto-expiring via expires_at)
    // ---------------------------------------------------------------

    public function setTransient(string $key, mixed $value, int $ttlSeconds): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $serialized = is_scalar($value) || is_null($value) ? $value : serialize($value);
        $expiresAt  = gmdate('Y-m-d H:i:s', time() + $ttlSeconds);

        $exists = $this->driver->selectVar(
            "SELECT id FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0 AND type = %s",
            [$this->plugin, $key, 'transient']
        );

        $data = [
            'option_value' => $serialized,
            'type'         => 'transient',
            'expires_at'   => $expiresAt,
            'autoload'     => 'no',
        ];

        if ($exists) {
            $result = $this->driver->update(
                'booleanpress_options',
                $data,
                ['plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => 0, 'type' => 'transient']
            );
        } else {
            $result = $this->driver->insert('booleanpress_options', array_merge($data, [
                'plugin'      => $this->plugin,
                'version'     => '1.0.0',
                'option_name' => $key,
                'ref_id'      => 0,
            ]));
        }

        $this->forget($key);

        return $result !== false;
    }

    public function getTransient(string $key, mixed $default = null): mixed
    {
        if (!$this->tableExists()) {
            return $default;
        }

        $row = $this->driver->select(
            "SELECT option_value, expires_at FROM {$this->tableName} WHERE plugin = %s AND option_name = %s AND ref_id = 0 AND type = %s LIMIT 1",
            [$this->plugin, $key, 'transient']
        );

        if (empty($row)) {
            return $default;
        }

        $row = $row[0];

        if (!empty($row['expires_at']) && strtotime($row['expires_at']) < time()) {
            $this->driver->delete('booleanpress_options', [
                'plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => 0, 'type' => 'transient',
            ]);
            return $default;
        }

        return $this->maybeUnserialize($row['option_value']);
    }

    public function deleteTransient(string $key): bool
    {
        if (!$this->tableExists()) {
            return false;
        }

        $this->forget($key);

        return $this->driver->delete('booleanpress_options', [
            'plugin' => $this->plugin, 'option_name' => $key, 'ref_id' => 0, 'type' => 'transient',
        ]) !== false;
    }

    // ---------------------------------------------------------------
    //  Expiry / pruning
    // ---------------------------------------------------------------

    /**
     * Delete all expired rows for this plugin.
     */
    public function deleteExpired(): int
    {
        if (!$this->tableExists()) {
            return 0;
        }

        $now           = gmdate('Y-m-d H:i:s');
        $this->missing = [];

        $rows = $this->driver->select(
            "SELECT id FROM {$this->tableName} WHERE plugin = %s AND expires_at IS NOT NULL AND expires_at < %s",
            [$this->plugin, $now]
        );

        if (empty($rows)) {
            return 0;
        }

        $deleted = 0;
        foreach ($rows as $row) {
            $result = $this->driver->statement(
                "DELETE FROM {$this->tableName} WHERE id = " . (int) $row['id']
            );
            if ($result !== false) {
                $deleted++;
            }
        }

        return $deleted;
    }

    // ---------------------------------------------------------------
    //  Internals
    // ---------------------------------------------------------------

    /**
     * Drop what this request remembers about a key (its cached value or its absence) after
     * a write that did not go through {@see set()}.
     *
     * @since 0.2.2
     */
    protected function forget(string $key): void
    {
        if ($this->cache !== null) {
            unset($this->cache[$key]);
        }
        unset($this->missing[$key]);
    }

    protected function tableExists(): bool
    {
        if ($this->tableSeen) {
            return true;
        }

        $this->tableSeen = $this->driver->tableExists('booleanpress_options');

        return $this->tableSeen;
    }

    /**
     * Unserialize a stored value when it has the shape of serialized PHP data; a plain
     * scalar is returned as it is (no `unserialize()` call, so no warning to silence).
     *
     * @since 0.2.2 Plain scalars no longer go through `unserialize()`.
     */
    protected function maybeUnserialize(mixed $value): mixed
    {
        if (!is_string($value) || $value === '' || preg_match('/^(?:[adObisN]:|N;)/', $value) !== 1) {
            return $value;
        }

        $unserialized = @unserialize($value, ['allowed_classes' => false]);
        if ($unserialized !== false || $value === 'b:0;') {
            return $unserialized;
        }

        return $value;
    }
}
