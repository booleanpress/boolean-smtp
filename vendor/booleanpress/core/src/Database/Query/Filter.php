<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Query;

use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Support\Str;

/**
 * Abstract Filter
 *
 * Base class for creating model query filters.
 * Maps request input keys to dedicated filter methods.
 *
 * Example:
 *   class UserFilter extends Filter
 *   {
 *       protected array $filters = ['status', 'role', 'search'];
 *
 *       protected function filterStatus(string $value): void
 *       {
 *           $this->builder->where('status', $value);
 *       }
 *
 *       protected function filterRole(string $value): void
 *       {
 *           $this->builder->where('role', $value);
 *       }
 *
 *       protected function filterSearch(string $value): void
 *       {
 *           $this->builder->where('name', 'LIKE', '%' . $value . '%');
 *       }
 *   }
 *
 *   // Usage via Filterable trait on model:
 *   User::query()->filter(['status' => 'active', 'search' => 'john'])->get();
 */
abstract class Filter
{
    protected QueryBuilder $builder;

    /**
     * @var array<string, mixed>
     */
    protected array $input;

    /**
     * Allowed filter keys.
     * Only keys listed here will be processed.
     *
     * @var array<int, string>
     */
    protected array $filters = [];

    /**
     * @param QueryBuilder $builder
     * @param array<string, mixed> $input
     */
    public function __construct(QueryBuilder $builder, array $input)
    {
        $this->builder = $builder;
        $this->input = $input;
    }

    /**
     * Apply all matching filters to the query builder.
     */
    public function apply(): QueryBuilder
    {
        foreach ($this->getFilters() as $filterName) {
            $value = $this->input[$filterName] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            $method = 'filter' . Str::studly($filterName);

            if (method_exists($this, $method)) {
                $this->$method($value);
            }
        }

        return $this->builder;
    }

    /**
     * Get the list of allowed filter keys.
     *
     * @return array<int, string>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /**
     * Get the query builder instance.
     */
    public function getBuilder(): QueryBuilder
    {
        return $this->builder;
    }
}
