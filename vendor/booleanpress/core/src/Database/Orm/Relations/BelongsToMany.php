<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Relations;

use BooleanSmtp\Core\Database\Orm\Model;
use BooleanSmtp\Core\Support\Collection;
use function BooleanSmtp\Core\app;

/**
 * BelongsToMany Relationship
 *
 * Represents a many-to-many relationship using a pivot table.
 * Example: User belongsToMany Roles (via user_roles pivot table)
 */
class BelongsToMany
{
    protected Model $parent;
    protected string $related;
    protected string $table;
    protected string $foreignPivotKey;
    protected string $relatedPivotKey;
    protected string $parentKey;
    protected string $relatedKey;

    /**
     * @var array<string> Pivot columns to retrieve
     */
    protected array $pivotColumns = [];

    public function __construct(
        Model $parent,
        string $related,
        string $table,
        string $foreignPivotKey,
        string $relatedPivotKey,
        string $parentKey = 'id',
        string $relatedKey = 'id'
    ) {
        $this->validateColumnName($foreignPivotKey);
        $this->validateColumnName($relatedPivotKey);
        $this->validateColumnName($parentKey);
        $this->validateColumnName($relatedKey);

        $this->parent = $parent;
        $this->related = $related;
        $this->table = $table;
        $this->foreignPivotKey = $foreignPivotKey;
        $this->relatedPivotKey = $relatedPivotKey;
        $this->parentKey = $parentKey;
        $this->relatedKey = $relatedKey;
    }

    /**
     * Get the database driver.
     */
    protected function driver(): \BooleanSmtp\Core\Database\Drivers\DriverInterface
    {
        return app(\BooleanSmtp\Core\Database\Drivers\DriverInterface::class);
    }

    /**
     * Get all related models.
     *
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        $parentKey = $this->parent->{$this->parentKey};

        if ($parentKey === null) {
            return new Collection([]);
        }

        $relatedInstance = new $this->related();
        $relatedTable = $relatedInstance->getTable();

        // Build pivot select columns
        $pivotSelect = '';
        foreach ($this->pivotColumns as $column) {
            $pivotSelect .= ", pivot.`{$column}` as pivot_{$column}";
        }

        $sql = sprintf(
            "SELECT related.*, pivot.`{$this->foreignPivotKey}`, pivot.`{$this->relatedPivotKey}`{$pivotSelect}
             FROM `{$this->getTable()}` AS pivot
             INNER JOIN `{$relatedTable}` AS related ON related.`{$this->relatedKey}` = pivot.`{$this->relatedPivotKey}`
             WHERE pivot.`{$this->foreignPivotKey}` = %%s",
        );

        $rows = $this->driver()->select($sql, [$parentKey]);

        return $this->hydrate($rows);
    }

    /**
     * Get the pivot table name with prefix.
     */
    public function getTable(): string
    {
        return $this->driver()->getTable($this->table);
    }

    /**
     * Specify which pivot columns to retrieve.
     *
     * @param array<string> $columns
     */
    public function withPivot(array $columns): static
    {
        foreach ($columns as $column) {
            $this->validateColumnName($column);
        }

        $this->pivotColumns = array_merge($this->pivotColumns, $columns);
        return $this;
    }

    /**
     * Validate that a column name is safe for use in SQL queries.
     *
     * @throws \InvalidArgumentException
     */
    private function validateColumnName(string $name): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $name)) {
            throw new \InvalidArgumentException(
                "Invalid column name: '{$name}'. Column names must match [a-zA-Z_][a-zA-Z0-9_]*."
            );
        }
    }

    /**
     * Attach models to the parent.
     *
     * @param int|array<int>|array<int, array<string, mixed>> $ids
     * @param array<string, mixed> $attributes Additional pivot attributes
     */
    public function attach(int|array $ids, array $attributes = []): void
    {
        $parentKey = $this->parent->{$this->parentKey};

        if (!is_array($ids)) {
            $ids = [$ids => $attributes];
        }

        foreach ($ids as $id => $pivotAttributes) {
            if (is_numeric($id)) {
                $relatedId = is_array($pivotAttributes) ? $id : $pivotAttributes;
                $pivotData = is_array($pivotAttributes) ? $pivotAttributes : $attributes;
            } else {
                $relatedId = $id;
                $pivotData = $pivotAttributes;
            }

            $data = array_merge([
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $relatedId,
            ], $pivotData);

            $this->driver()->insert($this->table, $data);
        }
    }

    /**
     * Detach models from the parent.
     *
     * @param int|array<int>|null $ids Null to detach all
     */
    public function detach(int|array|null $ids = null): int
    {
        $parentKey = $this->parent->{$this->parentKey};

        if ($ids === null) {
            // Detach all
            $result = $this->driver()->delete($this->table, [
                $this->foreignPivotKey => $parentKey,
            ]);
            return $result === false ? 0 : $result;
        }

        if (!is_array($ids)) {
            $ids = [$ids];
        }

        $count = 0;
        foreach ($ids as $id) {
            $result = $this->driver()->delete($this->table, [
                $this->foreignPivotKey => $parentKey,
                $this->relatedPivotKey => $id,
            ]);
            if ($result) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Sync the relationship by attaching/detaching as needed.
     *
     * @param array<int>|array<int, array<string, mixed>> $ids
     * @return array<string, array<int>>
     */
    public function sync(array $ids): array
    {
        $current = $this->get()->pluck($this->relatedKey)->all();

        $detach = array_diff($current, array_keys($ids));
        $attach = array_diff(array_keys($ids), $current);

        if (!empty($detach)) {
            $this->detach($detach);
        }

        foreach ($attach as $id) {
            $attributes = $ids[$id] ?? [];
            $this->attach($id, is_array($attributes) ? $attributes : []);
        }

        return [
            'attached' => $attach,
            'detached' => $detach,
        ];
    }

    /**
     * Toggle the attachment of related models.
     *
     * @param array<int> $ids
     * @return array<string, array<int>>
     */
    public function toggle(array $ids): array
    {
        $current = $this->get()->pluck($this->relatedKey)->all();

        $detach = array_intersect($current, $ids);
        $attach = array_diff($ids, $current);

        $this->detach($detach);

        foreach ($attach as $id) {
            $this->attach($id);
        }

        return [
            'attached' => $attach,
            'detached' => $detach,
        ];
    }

    /**
     * Get count of related models.
     */
    public function count(): int
    {
        $parentKey = $this->parent->{$this->parentKey};

        $count = $this->driver()->selectVar(
            "SELECT COUNT(*) FROM `{$this->getTable()}` WHERE `{$this->foreignPivotKey}` = %s",
            [$parentKey]
        );

        return (int) $count;
    }

    /**
     * Eager load the relationship for a collection of models.
     *
     * @param Collection<int, Model> $models
     * @param string $relation
     * @return void
     */
    public function eagerLoad(Collection $models, string $relation): void
    {
        $keys = $models->pluck($this->parentKey)->unique()->filter()->all();

        if (empty($keys)) {
            foreach ($models as $model) {
                $model->setRelation($relation, new Collection([]));
            }
            return;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->related();
        $relatedTable = $relatedInstance->getTable();

        // Build pivot select columns
        $pivotSelect = '';
        foreach ($this->pivotColumns as $column) {
            $pivotSelect .= ", pivot.`{$column}` as pivot_{$column}";
        }

        $placeholders = implode(',', array_fill(0, count($keys), '%s'));

        $sql = sprintf(
            "SELECT related.*, pivot.`{$this->foreignPivotKey}`, pivot.`{$this->relatedPivotKey}`{$pivotSelect}
             FROM `{$this->getTable()}` AS pivot
             INNER JOIN `{$relatedTable}` AS related ON related.`{$this->relatedKey}` = pivot.`{$this->relatedPivotKey}`
             WHERE pivot.`{$this->foreignPivotKey}` IN (%s)",
            $placeholders
        );

        $rows = $this->driver()->select($sql, $keys);

        $results = $this->hydrate($rows);

        $grouped = $results->groupBy($this->foreignPivotKey);

        foreach ($models as $model) {
            $model->setRelation($relation, $grouped->get($model->{$this->parentKey}, new Collection([])));
        }
    }

    /**
     * Hydrate models from rows.
     *
     * @param array<array<string, mixed>> $rows
     * @return Collection<int, Model>
     */
    protected function hydrate(array $rows): Collection
    {
        $models = [];

        foreach ($rows as $row) {
            $model = new $this->related();
            $model->fill($row);
            $model->syncOriginal();
            $model->setExists(true);
            $models[] = $model;
        }

        return new Collection($models);
    }
}
