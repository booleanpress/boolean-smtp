<?php
/**
 * Email log variant used when importing historical rows from another plugin.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Models;

/**
 * Used when persisting imported rows so {@see EmailLog} does not overwrite historical timestamps:
 * the automatic timestamps are off and `created_at`/`updated_at` are fillable, so the row keeps
 * the moment the source plugin recorded the message.
 *
 * @since 1.0.0
 */
class MigratedEmailLog extends EmailLog
{
    /**
     * Whether to automatically maintain created_at/updated_at timestamps.
     *
     * Disabled so imported rows keep the timestamps from the source data instead of being
     * overwritten with the current time.
     *
     * @since 1.0.0
     * @var bool
     */
    protected bool $timestamps = false;

    /**
     * Accept the source's timestamps as attributes, on top of the parent's fillable list.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $attributes Attributes to fill.
     */
    public function __construct(array $attributes = [])
    {
        $this->fillable = array_values(array_unique(array_merge($this->fillable, ['created_at', 'updated_at'])));
        parent::__construct($attributes);
    }
}
