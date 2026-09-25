<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

use BooleanSmtp\Core\Database\Orm\Revision;

/**
 * HasRevisions Trait
 *
 * Automatically stores revisions when a model is updated.
 * Supports restoring to a previous revision.
 */
trait HasRevisions
{
    /**
     * Boot the has revisions trait.
     */
    public static function bootHasRevisions(): void
    {
        static::updated(function ($model) {
            $model->storeRevision('update');
        });

        static::deleted(function ($model) {
            $model->storeRevision('delete');
        });
    }

    /**
     * Store a new revision for the model.
     */
    public function storeRevision(string $action = 'update'): void
    {
        Revision::create([
            'model_type' => static::class,
            'model_id' => $this->getKey(),
            'data' => serialize($action === 'delete' ? $this->getAttributes() : $this->getOriginal()),
            'user_id' => get_current_user_id(),
        ]);
    }

    /**
     * Get all revisions for the model.
     */
    public function revisions()
    {
        return Revision::where('model_type', static::class)
            ->where('model_id', $this->getKey())
            ->orderBy('created_at', 'DESC')
            ->get();
    }

    /**
     * Restore the model to a specific revision.
     */
    public function restoreRevision(int $revisionId): bool
    {
        $revision = Revision::find($revisionId);

        if (!$revision || $revision->model_type !== static::class || $revision->model_id != $this->getKey()) {
            return false;
        }

        $data = unserialize($revision->data, ['allowed_classes' => false]);

        if (!is_array($data)) {
            return false;
        }

        return $this->update($data);
    }

    /**
     * Restore a deleted model from its last revision.
     */
    public static function undelete(int $modelId): ?static
    {
        $revision = Revision::where('model_type', static::class)
            ->where('model_id', $modelId)
            ->orderBy('created_at', 'DESC')
            ->first();

        if (!$revision) {
            return null;
        }

        $data = unserialize($revision->data, ['allowed_classes' => false]);

        if (!is_array($data)) {
            return null;
        }

        return static::create($data);
    }
}
