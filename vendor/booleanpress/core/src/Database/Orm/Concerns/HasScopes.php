<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

use BooleanSmtp\Core\Database\Orm\QueryBuilder;
use BooleanSmtp\Core\Support\Str;

/**
 * HasScopes Trait
 *
 * Provides scope method support for Models.
 * Scopes define reusable query constraints that can be chained.
 *
 * Example:
 *   // In Model
 *   public function scopeActive(QueryBuilder $query): QueryBuilder
 *   {
 *       return $query->where('status', 'active');
 *   }
 *
 *   // Usage
 *   Task::active()->get();
 */
trait HasScopes
{
    /**
     * Apply a scope to the query builder.
     *
     * @param string $scope The scope method name (without 'scope' prefix)
     * @param array<mixed> $parameters Additional parameters for the scope
     */
    public static function applyScope(string $scope, array $parameters = []): QueryBuilder
    {
        $query = static::query();
        $method = 'scope' . Str::studly($scope);
        $instance = new static();

        if (method_exists($instance, $method)) {
            return $instance->$method($query, ...$parameters);
        }

        throw new \BadMethodCallException(
            sprintf('Scope [%s] does not exist on model [%s]', $scope, static::class)
        );
    }

    /**
     * Handle dynamic static method calls for scopes.
     *
     * @param string $method
     * @param array<mixed> $parameters
     */
    public static function __callStatic(string $method, array $parameters): mixed
    {
        $instance = new static();
        $scopeMethod = 'scope' . Str::studly($method);

        if (method_exists($instance, $scopeMethod)) {
            $query = static::query();
            return $instance->$scopeMethod($query, ...$parameters);
        }

        throw new \BadMethodCallException(
            sprintf('Method [%s] does not exist on model [%s]', $method, static::class)
        );
    }
}
