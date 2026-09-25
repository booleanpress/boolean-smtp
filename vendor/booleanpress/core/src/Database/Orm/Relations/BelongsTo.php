<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Relations;

use BooleanSmtp\Core\Database\Orm\Model;

/**
 * BelongsTo Relationship
 *
 * Represents an inverse one-to-one or one-to-many relationship
 * where the child model belongs to a parent model.
 *
 * Example: Task belongsTo User
 */
class BelongsTo
{
    protected Model $child;
    protected string $related;
    protected string $foreignKey;
    protected string $ownerKey;

    public function __construct(Model $child, string $related, string $foreignKey, string $ownerKey = 'id')
    {
        $this->child = $child;
        $this->related = $related;
        $this->foreignKey = $foreignKey;
        $this->ownerKey = $ownerKey;
    }

    /**
     * Get the result of the relationship.
     */
    public function get(): ?Model
    {
        $foreignKeyValue = $this->child->{$this->foreignKey};

        if ($foreignKeyValue === null) {
            return null;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->related();

        return $relatedInstance::find($foreignKeyValue);
    }

    /**
     * Alias for get().
     */
    public function first(): ?Model
    {
        return $this->get();
    }

    /**
     * Eager load the relationship for a collection of models.
     *
     * @param \BooleanSmtp\Core\Support\Collection $models
     * @param string $relation
     * @return void
     */
    public function eagerLoad(\BooleanSmtp\Core\Support\Collection $models, string $relation): void
    {
        $keys = $models->pluck($this->foreignKey)->unique()->filter()->all();

        if (empty($keys)) {
            foreach ($models as $model) {
                $model->setRelation($relation, null);
            }
            return;
        }

        /** @var Model $relatedInstance */
        $relatedInstance = new $this->related();

        $results = $relatedInstance::query()
            ->whereIn($this->ownerKey, $keys)
            ->get();

        $dictionary = $results->keyBy($this->ownerKey);

        foreach ($models as $model) {
            $model->setRelation($relation, $dictionary->get($model->{$this->foreignKey}));
        }
    }
}
