<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm;

use BooleanSmtp\Core\Support\Arr;
use BooleanSmtp\Core\Support\Collection;
use BooleanSmtp\Core\Support\Str;
use BooleanSmtp\Core\Database\Orm\Concerns\HasRelationships;
use BooleanSmtp\Core\Database\Orm\Concerns\HasScopes;
use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use BooleanSmtp\Core\Exceptions\ModelNotFoundException;
use function BooleanSmtp\Core\app;
use function BooleanSmtp\Core\class_basename;
use function BooleanSmtp\Core\class_uses_recursive;

/**
 * Base Model
 *
 * Simple Active Record implementation for WordPress.
 * Uses DriverInterface for database operations.
 */
abstract class Model implements \JsonSerializable
{
    use HasRelationships;
    use HasScopes;

    /**
     * Global scopes applied to the model.
     *
     * @var array<string, array<string, callable>>
     */
    protected static array $globalScopes = [];

    /**
     * Models that have been booted.
     *
     * @var array<string, bool>
     */
    protected static array $booted = [];

    /**
     * The registered model events.
     *
     * @var array<string, array<string, array<\Closure>>>
     */
    protected static array $events = [];

    /**
     * The registered observers for each model class.
     *
     * @var array<string, array<string>>
     */
    protected static array $observers = [];

    /**
     * Excluded global scopes for this query.
     *
     * @var array<string>
     */
    protected array $withoutScopes = [];

    /**
     * The table associated with the model (without prefix).
     */
    protected string $table = '';

    /**
     * The primary key for the model.
     */
    protected string $primaryKey = 'id';

    /**
     * The primary key type.
     */
    protected string $keyType = 'int';

    /**
     * Indicates if the model's ID is auto-incrementing.
     */
    protected bool $incrementing = true;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected array $fillable = [];

    /**
     * The attributes that aren't mass assignable.
     *
     * @var array<string>
     */
    protected array $guarded = ['id'];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<string>
     */
    protected array $hidden = [];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected array $casts = [];

    /**
     * Indicates if the model should be timestamped.
     */
    protected bool $timestamps = true;

    /**
     * The name of the "created at" column.
     */
    protected string $createdAt = 'created_at';

    /**
     * The name of the "updated at" column.
     */
    protected string $updatedAt = 'updated_at';

    /**
     * The model's attributes.
     *
     * @var array<string, mixed>
     */
    protected array $attributes = [];

    /**
     * The model's original attributes.
     *
     * @var array<string, mixed>
     */
    protected array $original = [];

    /**
     * Indicates if the model exists in database.
     */
    protected bool $exists = false;

    /**
     * The loaded relationships for the model.
     *
     * @var array<string, mixed>
     */
    protected array $relations = [];

    /**
     * The database driver.
     */
    protected static ?DriverInterface $driver = null;

    /**
     * Create a new model instance.
     *
     * @param array<string, mixed> $attributes
     */
    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    /**
     * Get the database driver.
     */
    protected static function driver(): DriverInterface
    {
        if (static::$driver === null) {
            static::$driver = app(DriverInterface::class);
        }

        return static::$driver;
    }

    /**
     * Get the table name with prefix.
     */
    public function getTable(): string
    {
        if (empty($this->table)) {
            // Convert class name to table name
            $className = class_basename($this);
            $this->table = Str::snake(Str::plural($className));
        }

        return static::driver()->getTable($this->table);
    }

    /**
     * Get the primary key name.
     */
    public function getKeyName(): string
    {
        return $this->primaryKey;
    }

    /**
     * Get the primary key value.
     */
    public function getKey(): mixed
    {
        return $this->getAttribute($this->getKeyName());
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array<string, mixed> $attributes
     */
    public function fill(array $attributes): static
    {
        foreach ($attributes as $key => $value) {
            if ($this->isFillable($key)) {
                $this->setAttribute($key, $value);
            }
        }

        return $this;
    }

    /**
     * Register an observer for the model.
     *
     * @param class-string<Observer> $observerClass
     */
    public static function observe(string $observerClass): void
    {
        $events = ['creating', 'created', 'updating', 'updated', 'deleting', 'deleted', 'saving', 'saved'];
        $observer = new $observerClass();

        foreach ($events as $event) {
            if (method_exists($observer, $event)) {
                static::registerModelEvent($event, \Closure::fromCallable([$observer, $event]));
            }
        }

        static::$observers[static::class][] = $observerClass;
    }

    /**
     * Register event listeners for model lifecycle events.
     */
    public static function creating(\Closure $callback): void
    {
        static::registerModelEvent('creating', $callback);
    }

    public static function created(\Closure $callback): void
    {
        static::registerModelEvent('created', $callback);
    }

    public static function updating(\Closure $callback): void
    {
        static::registerModelEvent('updating', $callback);
    }

    public static function updated(\Closure $callback): void
    {
        static::registerModelEvent('updated', $callback);
    }

    public static function saving(\Closure $callback): void
    {
        static::registerModelEvent('saving', $callback);
    }

    public static function saved(\Closure $callback): void
    {
        static::registerModelEvent('saved', $callback);
    }

    public static function deleting(\Closure $callback): void
    {
        static::registerModelEvent('deleting', $callback);
    }

    public static function deleted(\Closure $callback): void
    {
        static::registerModelEvent('deleted', $callback);
    }

    /**
     * Register a model event.
     */
    protected static function registerModelEvent(string $event, \Closure $callback): void
    {
        static::$events[static::class][$event][] = $callback;
    }

    /**
     * Fire the given event for the model.
     *
     * Before-events (creating, updating, saving, deleting) return false
     * if any listener returns false, which cancels the operation.
     *
     * @return bool|null Returns false if a before-event listener cancels the operation
     */
    protected function fireModelEvent(string $event): ?bool
    {
        if (!isset(static::$events[static::class][$event])) {
            return null;
        }

        foreach (static::$events[static::class][$event] as $callback) {
            $result = $callback($this);

            // Before-events can cancel the operation by returning false
            if ($result === false) {
                return false;
            }
        }

        return null;
    }

    /**
     * Determine if the given attribute may be mass assigned.
     */
    public function isFillable(string $key): bool
    {
        if (in_array($key, $this->guarded, true)) {
            return false;
        }

        if (empty($this->fillable)) {
            return true;
        }

        return in_array($key, $this->fillable, true);
    }

    /**
     * Get an attribute value.
     */
    public function getAttribute(string $key): mixed
    {
        if (!array_key_exists($key, $this->attributes)) {
            return null;
        }

        $value = $this->attributes[$key];

        // Apply cast
        if (isset($this->casts[$key])) {
            $value = $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Set an attribute value.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        $this->attributes[$key] = $value;
        return $this;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        $cast = $this->casts[$key];

        return match ($cast) {
            'int', 'integer' => (int) $value,
            'float', 'double', 'real' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array' => is_string($value) ? json_decode($value, true) : (array) $value,
            'object' => is_string($value) ? json_decode($value) : (object) $value,
            'json' => is_string($value) ? json_decode($value, true) : $value,
            'datetime' => $value ? new \DateTimeImmutable($value) : null,
            default => $value,
        };
    }

    /**
     * Prepare an attribute for persistence (wpdb expects scalars; arrays must be JSON).
     */
    protected function serializeAttributeForDatabase(string $key, mixed $value): mixed
    {
        if (!isset($this->casts[$key])) {
            return $value;
        }

        $cast = $this->casts[$key];

        if ($value === null) {
            return null;
        }

        return match ($cast) {
            'array', 'json' => $this->encodeJsonAttributeForDatabase($value),
            'bool', 'boolean' => $value ? 1 : 0,
            'int', 'integer' => (int) $value,
            default => $value,
        };
    }

    /**
     * Encode array/json cast values for MySQL JSON columns (wpdb expects scalars; raw arrays stringify to "Array").
     *
     * @param mixed $value
     */
    protected function encodeJsonAttributeForDatabase(mixed $value): string
    {
        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = json_last_error() === JSON_ERROR_NONE ? $decoded : [];
        }

        $flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
        if (\defined('JSON_INVALID_UTF8_SUBSTITUTE')) {
            $flags |= \JSON_INVALID_UTF8_SUBSTITUTE;
        }

        $encoded = json_encode($value, $flags);

        return $encoded !== false ? $encoded : '[]';
    }

    /**
     * @return array<string, mixed>
     */
    protected function getAttributesForInsert(): array
    {
        $out = [];
        foreach ($this->attributes as $key => $value) {
            $out[$key] = $this->serializeAttributeForDatabase($key, $value);
        }

        return $out;
    }

    /**
     * Get all of the model's attributes.
     *
     * @return array<string, mixed>
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * Get the dirty (changed) attributes.
     *
     * @return array<string, mixed>
     */
    public function getDirty(): array
    {
        $dirty = [];

        foreach ($this->attributes as $key => $value) {
            if (!array_key_exists($key, $this->original) || $this->original[$key] !== $value) {
                $dirty[$key] = $value;
            }
        }

        return $dirty;
    }

    /**
     * Determine if the model has been modified.
     */
    public function isDirty(): bool
    {
        return !empty($this->getDirty());
    }

    /**
     * Sync the original attributes with the current.
     */
    public function syncOriginal(): static
    {
        $this->original = $this->attributes;
        return $this;
    }

    /**
     * Find a model by its primary key.
     */
    public static function find(mixed $id): ?static
    {
        $model = new static();

        $row = static::driver()->selectRow(
            sprintf("SELECT * FROM `%s` WHERE `%s` = %%s LIMIT 1", $model->getTable(), $model->getKeyName()),
            [$id]
        );

        if ($row === null) {
            return null;
        }

        return $model->newFromRow($row);
    }

    /**
     * Find a model by its primary key or throw an exception.
     */
    public static function findOrFail(mixed $id): static
    {
        $model = static::find($id);

        if ($model === null) {
            // Carries code 404 so Error\Handler renders "Not Found" instead of a 500.
            throw (new ModelNotFoundException())->setModel(static::class, $id);
        }

        return $model;
    }

    /**
     * Get all models.
     *
     * @return Collection<int, static>
     */
    public static function all(): Collection
    {
        $model = new static();
        $rows = static::driver()->select(
            sprintf("SELECT * FROM `%s`", $model->getTable())
        );

        return static::hydrate($rows);
    }

    /**
     * Allowed SQL operators for where clauses.
     *
     * @var array<string>
     */
    protected const ALLOWED_OPERATORS = ['=', '!=', '<>', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

    /**
     * Validate a column name to prevent SQL injection.
     */
    protected static function validateColumnName(string $column): string
    {
        // Only allow alphanumeric characters and underscores
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid column name: %s', $column)
            );
        }
        return $column;
    }

    /**
     * Validate an operator to prevent SQL injection.
     */
    protected static function validateOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));
        if (!in_array($operator, static::ALLOWED_OPERATORS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid SQL operator: %s', $operator)
            );
        }
        return $operator;
    }

    /**
     * Start a query with a where clause.
     *
     * @return Collection<int, static>
     */
    public static function where(string $column, mixed $operator, mixed $value = null): Collection
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        $model = new static();

        // Validate column and operator to prevent SQL injection
        $column = static::validateColumnName($column);
        $operator = static::validateOperator($operator);

        $rows = static::driver()->select(
            sprintf("SELECT * FROM `%s` WHERE `%s` %s %%s", $model->getTable(), $column, $operator),
            [$value]
        );

        return static::hydrate($rows);
    }

    /**
     * Get the first matching model.
     */
    public static function first(): ?static
    {
        $model = new static();

        $row = static::driver()->selectRow(
            sprintf("SELECT * FROM `%s` LIMIT 1", $model->getTable())
        );

        if ($row === null) {
            return null;
        }

        return $model->newFromRow($row);
    }

    /**
     * Create a new model instance from a database row.
     *
     * @param array<string, mixed> $row
     */
    public function newFromRow(array $row): static
    {
        $model = new static();
        $model->attributes = $row;
        $model->original = $row;
        $model->exists = true;

        return $model;
    }

    /**
     * Hydrate a collection from database rows.
     *
     * @param array<array<string, mixed>> $rows
     * @return Collection<int, static>
     */
    public static function hydrate(array $rows): Collection
    {
        $models = [];
        $instance = new static();

        foreach ($rows as $row) {
            $models[] = $instance->newFromRow($row);
        }

        return new Collection($models);
    }

    /**
     * Create a new model and save it to the database.
     *
     * @param array<string, mixed> $attributes
     */
    public static function create(array $attributes): static
    {
        $model = new static($attributes);
        $model->save();

        return $model;
    }

    /**
     * Save the model to the database.
     */
    public function save(): bool
    {
        // Add timestamps
        if ($this->timestamps) {
            $now = $this->freshTimestamp();

            if (!$this->exists) {
                $this->setAttribute($this->createdAt, $now);
            }

            $this->setAttribute($this->updatedAt, $now);
        }

        if ($this->exists) {
            return $this->performUpdate();
        }

        return $this->performInsert();
    }

    /**
     * The current time as timestamp columns store it: UTC, `Y-m-d H:i:s`.
     *
     * Plugins on the framework store their explicit times with `gmdate()` and read report
     * windows as UTC, so `created_at`, `updated_at` and `deleted_at` are UTC too. (A column's
     * database-side `CURRENT_TIMESTAMP` default follows the database session's time zone, not
     * this method; the ORM always sets these columns itself.) WordPress's `current_time('mysql')` would return the site's
     * local time and shift these columns by the site's UTC offset. Override on a model that
     * genuinely stores local time.
     */
    public function freshTimestamp(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    /**
     * Perform an insert.
     */
    protected function performInsert(): bool
    {
        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->fireModelEvent('creating') === false) {
            return false;
        }

        $result = static::driver()->insert(
            $this->table, // insert() in DriverInterface expects table without prefix
            $this->getAttributesForInsert()
        );

        if ($result === false) {
            return false;
        }

        // Set the ID if auto-incrementing
        if ($this->incrementing) {
            $this->setAttribute($this->primaryKey, $result);
        }

        $this->exists = true;
        $this->syncOriginal();

        $this->fireModelEvent('created');
        $this->fireModelEvent('saved');

        return true;
    }

    /**
     * Perform an update.
     */
    protected function performUpdate(): bool
    {
        $dirty = $this->getDirty();

        if (empty($dirty)) {
            return true;
        }

        if ($this->fireModelEvent('saving') === false) {
            return false;
        }

        if ($this->fireModelEvent('updating') === false) {
            return false;
        }

        $prepared = [];
        foreach ($dirty as $key => $value) {
            $prepared[$key] = $this->serializeAttributeForDatabase($key, $value);
        }

        $result = static::driver()->update(
            $this->table,
            $prepared,
            [$this->primaryKey => $this->getKey()]
        );

        if ($result === false) {
            return false;
        }

        $this->syncOriginal();

        $this->fireModelEvent('updated');
        $this->fireModelEvent('saved');

        return true;
    }

    /**
     * Delete the model from the database.
     */
    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }

        if ($this->fireModelEvent('deleting') === false) {
            return false;
        }

        $result = static::driver()->delete(
            $this->table,
            [$this->primaryKey => $this->getKey()]
        );

        if ($result === false) {
            return false;
        }

        $this->exists = false;

        $this->fireModelEvent('deleted');

        return true;
    }

    /**
     * Update the model in the database.
     *
     * @param array<string, mixed> $attributes
     */
    public function update(array $attributes = []): bool
    {
        $this->fill($attributes);
        return $this->save();
    }

    /**
     * Refresh the model from the database.
     */
    public function refresh(): static
    {
        if (!$this->exists) {
            return $this;
        }

        $fresh = static::find($this->getKey());

        if ($fresh !== null) {
            $this->attributes = $fresh->attributes;
            $this->original = $fresh->original;
        }

        return $this;
    }

    /**
     * Convert the model to an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $attributes = $this->attributes;

        // Remove hidden attributes
        foreach ($this->hidden as $hidden) {
            unset($attributes[$hidden]);
        }

        // Apply casts
        foreach ($attributes as $key => $value) {
            if (isset($this->casts[$key])) {
                $attributes[$key] = $this->castAttribute($key, $value);
            }
        }

        return $attributes;
    }

    /**
     * Convert the model to JSON.
     */
    public function toJson(int $options = 0): string
    {
        return json_encode($this->toArray(), $options);
    }

    /**
     * Convert the object into something JSON serializable.
     */
    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    /**
     * Dynamically retrieve attributes.
     */
    public function __get(string $key): mixed
    {
        return $this->getAttribute($key);
    }

    /**
     * Dynamically set attributes.
     */
    public function __set(string $key, mixed $value): void
    {
        $this->setAttribute($key, $value);
    }

    /**
     * Determine if an attribute exists.
     */
    public function __isset(string $key): bool
    {
        return isset($this->attributes[$key]);
    }

    /**
     * Unset an attribute.
     */
    public function __unset(string $key): void
    {
        unset($this->attributes[$key]);
    }

    /**
     * Begin a new query for the model.
     */
    public static function query(): QueryBuilder
    {
        return new QueryBuilder(static::class);
    }

    /**
     * Begin a query with eager loading.
     *
     * @param string|array<string> $relations
     */
    public static function with(string|array $relations): QueryBuilder
    {
        return static::query()->with($relations);
    }

    /**
     * Set a relation on the model.
     */
    public function setRelation(string $name, mixed $value): static
    {
        $this->relations[$name] = $value;
        return $this;
    }

    /**
     * Get a loaded relation.
     */
    public function getRelation(string $name): mixed
    {
        return $this->relations[$name] ?? null;
    }

    /**
     * Set the exists flag.
     */
    public function setExists(bool $value): static
    {
        $this->exists = $value;
        return $this;
    }

    /**
     * Add a global scope to the model.
     *
     * @param string $name
     * @param callable $scope
     */
    public static function addGlobalScope(string $name, callable $scope): void
    {
        static::$globalScopes[static::class][$name] = $scope;
    }

    /**
     * Get the global scopes for this model.
     *
     * @return array<string, callable>
     */
    public static function getGlobalScopes(): array
    {
        return static::$globalScopes[static::class] ?? [];
    }

    /**
     * Remove a global scope.
     */
    public static function removeGlobalScope(string $name): void
    {
        unset(static::$globalScopes[static::class][$name]);
    }

    /**
     * Boot the model if it hasn't been booted.
     */
    public static function bootIfNotBooted(): void
    {
        if (!isset(static::$booted[static::class])) {
            static::$booted[static::class] = true;
            static::boot();
        }
    }

    /**
     * Boot the model.
     * Calls boot methods on traits (e.g., bootSoftDeletes).
     */
    protected static function boot(): void
    {
        // Boot traits
        foreach (class_uses_recursive(static::class) as $trait) {
            $method = 'boot' . class_basename($trait);
            if (method_exists(static::class, $method)) {
                forward_static_call([static::class, $method]);
            }
        }
    }

    /**
     * Clear booted state (useful for testing).
     */
    public static function clearBootedModels(): void
    {
        static::$booted = [];
        static::$globalScopes = [];
        static::$events = [];
        static::$observers = [];
    }
}
