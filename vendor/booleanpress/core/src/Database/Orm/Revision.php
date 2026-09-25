<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm;

/**
 * Revision Model
 *
 * Stores model revisions for undo/redo functionality.
 */
class Revision extends Model
{
    protected string $table = 'booleanpress_revisions';

    protected array $fillable = [
        'model_type',
        'model_id',
        'data',
        'user_id',
    ];

    /**
     * Get the model associated with this revision.
     */
    public function model(): ?Model
    {
        $class = $this->model_type;
        if (!class_exists($class)) {
            return null;
        }

        return $class::find($this->model_id);
    }
}
