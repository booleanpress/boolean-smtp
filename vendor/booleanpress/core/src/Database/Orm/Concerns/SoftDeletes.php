<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

/**
 * SoftDeletes Trait
 *
 * Provides soft delete functionality for models.
 * Instead of permanently deleting records, marks them with a deleted_at timestamp.
 */
trait SoftDeletes
{
    /**
     * Indicates if the model is currently force deleting.
     */
    protected bool $forceDeleting = false;

    /**
     * Boot the soft deletes trait.
     */
    public static function bootSoftDeletes(): void
    {
        // Add global scope to exclude soft deleted records by default
        static::addGlobalScope('soft_deletes', function ($query) {
            $query->whereNull(static::getDeletedAtColumn());
        });
    }

    /**
     * Get the name of the "deleted at" column.
     */
    public static function getDeletedAtColumn(): string
    {
        return defined('static::DELETED_AT') ? static::DELETED_AT : 'deleted_at';
    }

    /**
     * Get the fully qualified "deleted at" column.
     */
    public function getQualifiedDeletedAtColumn(): string
    {
        return $this->getTable() . '.' . static::getDeletedAtColumn();
    }

    /**
     * Determine if the model is currently being force deleted.
     */
    public function isForceDeleting(): bool
    {
        return $this->forceDeleting;
    }

    /**
     * Determine if the model instance has been soft-deleted.
     */
    public function trashed(): bool
    {
        return !is_null($this->{static::getDeletedAtColumn()});
    }

    /**
     * Soft delete the model.
     */
    public function delete(): bool
    {
        if ($this->forceDeleting) {
            return $this->forceDelete();
        }

        $this->{static::getDeletedAtColumn()} = $this->freshTimestamp();

        return $this->save();
    }

    /**
     * Force delete the model from the database.
     */
    public function forceDelete(): bool
    {
        $this->forceDeleting = true;

        $result = $this->performDeleteOnModel();

        $this->forceDeleting = false;

        return $result;
    }

    /**
     * Actually delete the record from the database.
     */
    protected function performDeleteOnModel(): bool
    {
        $result = static::driver()->delete(
            $this->table,
            [$this->getKeyName() => $this->getKey()]
        );

        $this->exists = false;

        return $result !== false;
    }

    /**
     * Restore a soft-deleted model.
     */
    public function restore(): bool
    {
        $this->{static::getDeletedAtColumn()} = null;

        return $this->save();
    }

    /**
     * Query only trashed models.
     *
     * @return static
     */
    public static function onlyTrashed(): mixed
    {
        return static::query()
            ->withoutGlobalScope('soft_deletes')
            ->whereNotNull(static::getDeletedAtColumn());
    }

    /**
     * Query including trashed models.
     *
     * @return static
     */
    public static function withTrashed(): mixed
    {
        return static::query()->withoutGlobalScope('soft_deletes');
    }

    /**
     * Query without trashed models (default behavior).
     *
     * @return static
     */
    public static function withoutTrashed(): mixed
    {
        return static::query();
    }
}
