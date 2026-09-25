<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Relations;

use BooleanSmtp\Core\Database\Orm\Model;
use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Support\Collection;

/**
 * HasMany Relationship
 *
 * Represents a one-to-many relationship where the parent model
 * has multiple related child models.
 *
 * Example: User hasMany Tasks
 */
class HasMany
{
    protected Model $parent;
    protected string $related;
    protected string $foreignKey;
    protected string $localKey;

    public function __construct(Model $parent, string $related, string $foreignKey, string $localKey = 'id')
    {
        $this->parent = $parent;
        $this->related = $related;
        $this->foreignKey = $foreignKey;
        $this->localKey = $localKey;
    }

    /**
     * Get a query builder for the relationship, allowing further chaining.
     */
    public function getQuery(): QueryBuilder
    {
        $parentKey = $this->parent->{$this->localKey};

        $query = (new $this->related())::query();

        if ($parentKey !== null) {
            $query->where($this->foreignKey, '=', $parentKey);
        } else {
            // Impossible condition -- parent has no key
            $query->where('1', '=', '0');
        }

        return $query;
    }

    /**
     * Get the results of the relationship.
     *
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        $parentKey = $this->parent->{$this->localKey};

        if ($parentKey === null) {
            return new Collection([]);
        }

        return $this->getQuery()->get();
    }

    /**
     * Get the first result of the relationship.
     */
    public function first(): ?Model
    {
        return $this->getQuery()->first();
    }

    /**
     * Get the count of related models.
     */
    public function count(): int
    {
        return $this->getQuery()->count();
    }

    /**
     * Add a where clause to the relationship query.
     */
    public function where(string $column, mixed $operator, mixed $value = null): QueryBuilder
    {
        return $this->getQuery()->where($column, $operator, $value);
    }

    /**
     * Eager load the relationship for a collection of models.
     *
     * @param Collection<int, Model> $models
     * @param string $relation
     */
    public function eagerLoad(Collection $models, string $relation): void
    {
        $keys = $models->pluck($this->localKey)->unique()->filter()->all();

        if (empty($keys)) {
            foreach ($models as $model) {
                $model->setRelation($relation, new Collection([]));
            }
            return;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->related();

        $results = $relatedInstance::query()
            ->whereIn($this->foreignKey, $keys)
            ->get();

        $grouped = $results->groupBy($this->foreignKey);

        foreach ($models as $model) {
            $model->setRelation($relation, $grouped->get($model->{$this->localKey}, new Collection([])));
        }
    }
}
