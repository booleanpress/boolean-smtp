<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm;

/**
 * Base Model Observer
 *
 * Extend this class to observe model lifecycle events.
 * Implement any of the following methods to respond to events:
 *
 * - creating(Model $model): bool|void  -- Before insert (return false to cancel)
 * - created(Model $model): void        -- After insert
 * - updating(Model $model): bool|void  -- Before update (return false to cancel)
 * - updated(Model $model): void        -- After update
 * - saving(Model $model): bool|void    -- Before insert or update (return false to cancel)
 * - saved(Model $model): void          -- After insert or update
 * - deleting(Model $model): bool|void  -- Before delete (return false to cancel)
 * - deleted(Model $model): void        -- After delete
 */
abstract class Observer
{
}
