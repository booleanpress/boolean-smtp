<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

use BooleanSmtp\Core\Database\Orm\Relations\HasMany;
use BooleanSmtp\Core\Database\Orm\Relations\HasOne;
use BooleanSmtp\Core\Database\Orm\Relations\BelongsTo;
use BooleanSmtp\Core\Support\Str;
use function BooleanSmtp\Core\class_basename;

/**
 * HasRelationships Trait
 *
 * Provides relationship definition methods for the Model class.
 */
trait HasRelationships
{
    /**
     * Define a one-to-many relationship.
     *
     * @param string $related The related model class
     * @param string|null $foreignKey The foreign key on the related model
     * @param string|null $localKey The local key on this model
     */
    protected function hasMany(string $related, ?string $foreignKey = null, ?string $localKey = null): HasMany
    {
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new HasMany($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define a one-to-one relationship.
     *
     * @param string $related The related model class
     * @param string|null $foreignKey The foreign key on the related model
     * @param string|null $localKey The local key on this model
     */
    protected function hasOne(string $related, ?string $foreignKey = null, ?string $localKey = null): HasOne
    {
        $foreignKey = $foreignKey ?? $this->getForeignKey();
        $localKey = $localKey ?? $this->getKeyName();

        return new HasOne($this, $related, $foreignKey, $localKey);
    }

    /**
     * Define an inverse one-to-one or one-to-many relationship.
     *
     * @param string $related The related model class
     * @param string|null $foreignKey The foreign key on this model
     * @param string|null $ownerKey The primary key on the related model
     */
    protected function belongsTo(string $related, ?string $foreignKey = null, ?string $ownerKey = null): BelongsTo
    {
        // Guess foreign key from the related model name
        if ($foreignKey === null) {
            $relatedBasename = class_basename($related);
            $foreignKey = Str::snake($relatedBasename) . '_id';
        }

        $ownerKey = $ownerKey ?? 'id';

        return new BelongsTo($this, $related, $foreignKey, $ownerKey);
    }

    /**
     * Get the default foreign key name for this model.
     *
     * Example: For model "User", returns "user_id".
     */
    protected function getForeignKey(): string
    {
        return Str::snake(class_basename($this)) . '_id';
    }

    /**
     * Get the relation value from the model.
     * Caches the result for repeated access.
     *
     * @param string $method The relationship method name
     */
    protected function getRelationValue(string $method): mixed
    {
        // Check if already loaded
        if (isset($this->relations[$method])) {
            return $this->relations[$method];
        }

        // Load and cache the relationship
        if (method_exists($this, $method)) {
            $relation = $this->$method();

            if ($relation instanceof HasMany) {
                $this->relations[$method] = $relation->get();
            } elseif ($relation instanceof HasOne || $relation instanceof BelongsTo) {
                $this->relations[$method] = $relation->get();
            } else {
                return null;
            }

            return $this->relations[$method];
        }

        return null;
    }
}
