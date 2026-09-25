<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Relations;

use BooleanSmtp\Core\Database\Orm\Model;
use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Support\Collection;

/**
 * HasOne Relationship
 *
 * Represents a one-to-one relationship where the parent model
 * has one related child model.
 *
 * Example: User hasOne Profile
 */
class HasOne
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
            $query->where('1', '=', '0');
        }

        return $query;
    }

    /**
     * Get the result of the relationship.
     */
    public function get(): ?Model
    {
        $parentKey = $this->parent->{$this->localKey};

        if ($parentKey === null) {
            return null;
        }

        return $this->getQuery()->first();
    }

    /**
     * Alias for get().
     */
    public function first(): ?Model
    {
        return $this->get();
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
                $model->setRelation($relation, null);
            }
            return;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->related();

        $results = $relatedInstance::query()
            ->whereIn($this->foreignKey, $keys)
            ->get();

        $dictionary = $results->keyBy($this->foreignKey);

        foreach ($models as $model) {
            $model->setRelation($relation, $dictionary->get($model->{$this->localKey}));
        }
    }
}
