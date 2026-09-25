<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Database\Query\Filter;

/**
 * Filterable Trait
 *
 * Provides standardized search/filter support for Models.
 * Enables applying request filters (e.g., ?status=active&role=admin)
 * to query builders via a Filter class or inline array filters.
 *
 * Example using a Filter class:
 *   // In Model
 *   use Filterable;
 *   protected static string $filterClass = UserFilter::class;
 *
 *   // Usage
 *   User::query()->filter($request->query())->get();
 *
 * Example using scope:
 *   public function scopeFilter(QueryBuilder $query, array $filters): QueryBuilder
 *   {
 *       return (new UserFilter($query, $filters))->apply();
 *   }
 */
trait Filterable
{
    /**
     * The filter class to use for this model.
     * Override in your model to specify a custom filter class.
     *
     * @var string|null
     */
    // protected static string $filterClass = null;

    /**
     * Apply filters to the query builder using a Filter class.
     *
     * @param array<string, mixed> $filters Key-value pairs from request input
     * @return QueryBuilder
     */
    public function scopeFilter(QueryBuilder $query, array $filters): QueryBuilder
    {
        $filterClass = static::$filterClass ?? null;

        if ($filterClass !== null && is_subclass_of($filterClass, Filter::class)) {
            /** @var Filter $filter */
            $filter = new $filterClass($query, $filters);
            return $filter->apply();
        }

        // Fallback: apply simple where-equals filters for fillable fields
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($this->isFillable($key)) {
                $query->where($key, $value);
            }
        }

        return $query;
    }
}
