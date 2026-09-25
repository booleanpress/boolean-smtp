<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm;

use BooleanSmtp\Core\Database\Query\Filter;
use BooleanSmtp\Core\Database\Query\Grammars\Grammar;
use BooleanSmtp\Core\Database\Query\Grammars\MySqlGrammar;
use BooleanSmtp\Core\Pagination\LengthAwarePaginator;
use BooleanSmtp\Core\Support\Collection;
use Closure;
use Generator;
use function BooleanSmtp\Core\app;

/**
 * Query Builder
 *
 * Provides a fluent interface for building database queries.
 * Supports eager loading, scopes, filtering, chunking, and chainable where clauses.
 * Uses Grammar classes for dialect-specific SQL generation.
 */
class QueryBuilder
{
    protected string $modelClass;
    protected Model $model;
    protected Grammar $grammar;

    /**
     * @var array<array<string, mixed>>
     */
    protected array $wheres = [];

    /**
     * @var array<string>
     */
    protected array $eagerLoad = [];

    /**
     * @var array<string>
     */
    protected array $selects = [];

    /**
     * @var array<array{expression: string, bindings: array<int, mixed>}>
     */
    protected array $raws = [];

    protected bool $distinct = false;

    /**
     * @var array<string>
     */
    protected array $groups = [];

    /**
     * @var array<array{column: string, operator: string, value: mixed}>
     */
    protected array $havings = [];

    /**
     * @var array<array{table: string, first: string, operator: string, second: string, type: string}>
     */
    protected array $joins = [];

    protected ?int $limit            = null;
    protected ?int $offset           = null;

    /**
     * The order: a validated column name, or a whitelisted expression parsed by
     * {@see orderByRaw()} — `['function' => 'COALESCE', 'columns' => [...]]`.
     *
     * @var string|array{function: string, columns: list<string>}|null
     */
    protected string|array|null $orderBy = null;
    protected string $orderDirection = 'ASC';

    public function __construct(string $modelClass, ?Grammar $grammar = null)
    {
        $this->modelClass = $modelClass;
        $this->model      = new $modelClass();
        $this->grammar    = $grammar ?? new MySqlGrammar();
    }

    /**
     * Get the grammar instance.
     */
    public function getGrammar(): Grammar
    {
        return $this->grammar;
    }

    /**
     * Apply filters to the query using the model's Filterable trait or a Filter class.
     *
     * @param array<string, mixed> $filters Key-value pairs from request input
     * @param string|null $filterClass Optional Filter class override
     */
    public function filter(array $filters, ?string $filterClass = null): static
    {
        if ($filterClass !== null && is_subclass_of($filterClass, Filter::class)) {
            $filter = new $filterClass($this, $filters);
            $filter->apply();
            return $this;
        }

        // Delegate to the model's scopeFilter if available (via Filterable trait)
        if (method_exists($this->model, 'scopeFilter')) {
            $this->model->scopeFilter($this, $filters);
            return $this;
        }

        // Fallback: apply simple where-equals for each filter
        foreach ($filters as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $this->where($key, $value);
        }

        return $this;
    }

    /**
     * Add relationships to eager load.
     *
     * @param string|array<string> $relations
     */
    public function with(string|array $relations): static
    {
        if (is_string($relations)) {
            $relations = func_get_args();
        }

        $this->eagerLoad = array_merge($this->eagerLoad, $relations);

        return $this;
    }

    /**
     * Set the columns to select.
     *
     * @param string|array<string> ...$columns
     */
    public function select(string|array ...$columns): static
    {
        $this->selects = [];

        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->selects = array_merge($this->selects, $column);
            } else {
                $this->selects[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a raw select expression.
     *
     * @param array<int, mixed> $bindings
     */
    public function selectRaw(string $expression, array $bindings = []): static
    {
        $this->raws[] = [
            'expression' => $expression,
            'bindings' => $bindings,
        ];

        return $this;
    }

    /**
     * Force the query to return distinct results.
     */
    public function distinct(): static
    {
        $this->distinct = true;
        return $this;
    }

    /**
     * Add a "where in" clause to the query.
     */
    public function whereIn(string $column, array $values): static
    {
        $this->wheres[] = [
            'column'   => $column,
            'operator' => 'IN',
            'value'    => $values,
            'boolean'  => 'and',
            'type'     => 'basic',
        ];

        return $this;
    }

    /**
     * Add a where clause to the query.
     *
     * A Closure receives a fresh builder for the same model; the clauses it adds are wrapped in
     * parentheses and joined to the query with AND — `WHERE a = ? AND (b = ? OR c = ?)`.
     *
     * @since 0.2.6 Accepts a Closure for a nested group.
     *
     * @param string|Closure $column Column name, or a Closure that builds a nested group.
     * @param mixed          $operator Operator, or the value when only two arguments are given.
     * @param mixed          $value    Value to compare with.
     */
    public function where(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'and');
        }

        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => 'and',
            'type'     => 'basic',
        ];

        return $this;
    }

    /**
     * Add an "or where" clause to the query.
     *
     * A Closure builds a nested group joined with OR, as in {@see where()}.
     *
     * @since 0.2.6 Accepts a Closure for a nested group.
     *
     * @param string|Closure $column Column name, or a Closure that builds a nested group.
     * @param mixed          $operator Operator, or the value when only two arguments are given.
     * @param mixed          $value    Value to compare with.
     */
    public function orWhere(string|Closure $column, mixed $operator = null, mixed $value = null): static
    {
        if ($column instanceof Closure) {
            return $this->addNestedWhere($column, 'or');
        }

        if (func_num_args() === 2) {
            $value    = $operator;
            $operator = '=';
        }

        $this->wheres[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => 'or',
            'type'     => 'basic',
        ];

        return $this;
    }

    /**
     * Add a "contains" clause: `column LIKE %term%` with the term treated as data.
     *
     * The grammar escapes the characters LIKE would read as wildcards (`%`, `_`) so a search for
     * `100%` or `a_b` matches those characters literally, and declares the escape character
     * explicitly because SQLite has none by default.
     *
     * @since 0.2.6
     *
     * @param string $column  Column to search.
     * @param string $term    The text to look for, as typed.
     * @param string $boolean `and` or `or`.
     */
    public function whereLike(string $column, string $term, string $boolean = 'and'): static
    {
        $this->wheres[] = [
            'column'  => $column,
            'value'   => $term,
            'boolean' => strtolower($boolean) === 'or' ? 'or' : 'and',
            'type'    => 'like',
        ];

        return $this;
    }

    /**
     * Add a "contains" clause joined with OR — see {@see whereLike()}.
     *
     * @since 0.2.6
     *
     * @param string $column Column to search.
     * @param string $term   The text to look for, as typed.
     */
    public function orWhereLike(string $column, string $term): static
    {
        return $this->whereLike($column, $term, 'or');
    }

    /**
     * Build a nested where group from a Closure and append it with the given boolean.
     *
     * @param Closure $callback Receives a fresh builder for the same model.
     * @param string  $boolean  `and` or `or`.
     */
    protected function addNestedWhere(Closure $callback, string $boolean): static
    {
        $nested = new self($this->modelClass, $this->grammar);
        $callback($nested);

        if ($nested->wheres !== []) {
            $this->wheres[] = [
                'type'    => 'nested',
                'wheres'  => $nested->wheres,
                'boolean' => $boolean,
            ];
        }

        return $this;
    }

    /**
     * Add a "where null" clause to the query.
     */
    public function whereNull(string $column): static
    {
        $this->wheres[] = [
            'column'  => $column,
            'type'    => 'null',
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a "where not null" clause to the query.
     */
    public function whereNotNull(string $column): static
    {
        $this->wheres[] = [
            'column'  => $column,
            'type'    => 'not_null',
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a "where between" clause to the query.
     *
     * @param array{0: mixed, 1: mixed} $values
     */
    public function whereBetween(string $column, array $values): static
    {
        $this->wheres[] = [
            'column'  => $column,
            'type'    => 'between',
            'values'  => $values,
            'boolean' => 'and',
        ];

        return $this;
    }

    /**
     * Add a "group by" clause to the query.
     *
     * @param string|array<string> ...$columns
     */
    public function groupBy(string|array ...$columns): static
    {
        foreach ($columns as $column) {
            if (is_array($column)) {
                $this->groups = array_merge($this->groups, $column);
            } else {
                $this->groups[] = $column;
            }
        }

        return $this;
    }

    /**
     * Add a "having" clause to the query.
     */
    public function having(string $column, string $operator, mixed $value): static
    {
        $this->havings[] = [
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
        ];

        return $this;
    }

    /**
     * Add a join clause to the query.
     */
    public function join(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = [
            'table'    => $this->grammar->validateTableName($table),
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
            'type'     => 'inner',
        ];

        return $this;
    }

    /**
     * Add a left join clause to the query.
     */
    public function leftJoin(string $table, string $first, string $operator, string $second): static
    {
        $this->joins[] = [
            'table'    => $this->grammar->validateTableName($table),
            'first'    => $first,
            'operator' => $operator,
            'second'   => $second,
            'type'     => 'left',
        ];

        return $this;
    }

    /**
     * Set the limit for the query.
     */
    public function limit(int $limit): static
    {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Alias for limit.
     */
    public function take(int $count): static
    {
        return $this->limit($count);
    }

    /**
     * Set the offset for the query.
     */
    public function offset(int $offset): static
    {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Alias for offset.
     */
    public function skip(int $count): static
    {
        return $this->offset($count);
    }

    /**
     * Set the order by clause.
     */
    public function orderBy(string $column, string $direction = 'ASC'): static
    {
        $this->orderBy        = $column;
        $this->orderDirection = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';
        return $this;
    }

    /**
     * Order by a whitelisted expression over validated columns.
     *
     * Accepts `FUNCTION(column[, column…]) [ASC|DESC]` where the function is one of COALESCE,
     * IFNULL, LOWER or UPPER and every column passes the grammar's identifier check — for example
     * `COALESCE(last_attempted_at, created_at) DESC`. The expression is parsed here and rebuilt by
     * the grammar from its parts; the text itself never reaches the SQL.
     *
     * @since 0.2.6
     *
     * @param string $expression The expression, as described above.
     *
     * @throws \InvalidArgumentException When the expression is not of that shape or names an invalid column.
     */
    public function orderByRaw(string $expression): static
    {
        $pattern = '/^\s*(COALESCE|IFNULL|LOWER|UPPER)\s*\(\s*([A-Za-z_][A-Za-z0-9_.]*(?:\s*,\s*[A-Za-z_][A-Za-z0-9_.]*)*)\s*\)\s*(ASC|DESC)?\s*$/i';

        if (preg_match($pattern, $expression, $m) !== 1) {
            throw new \InvalidArgumentException(sprintf('Unsupported order expression: %s', $expression));
        }

        $columns = array_map('trim', explode(',', $m[2]));
        foreach ($columns as $column) {
            $this->grammar->validateColumnName($column);
        }

        $this->orderBy        = ['function' => strtoupper($m[1]), 'columns' => $columns];
        $this->orderDirection = strtoupper($m[3] ?? '') === 'DESC' ? 'DESC' : 'ASC';

        return $this;
    }

    /**
     * Execute the query and get results.
     *
     * @return Collection<int, Model>
     */
    public function get(): Collection
    {
        $models = $this->executeQuery();

        // Load eager loaded relationships
        if (!empty($this->eagerLoad) && $models->count() > 0) {
            $this->loadRelations($models);
        }

        return $models;
    }

    /**
     * Get the first result.
     */
    public function first(): ?Model
    {
        $this->limit(1);
        $results = $this->get();

        return $results->first();
    }

    /**
     * Get a count of results.
     */
    public function count(): int
    {
        return $this->countTotal();
    }

    /**
     * Delete every row the query matches and return how many were removed.
     *
     * Runs one DELETE statement from the where clauses only — no model events fire and
     * the query's limit, offset and joins are ignored. A query without a where clause is
     * refused, so a forgotten condition cannot empty a table.
     *
     * @since 0.2.1
     *
     * @return int The number of deleted rows.
     *
     * @throws \LogicException When the query has no where clause.
     */
    public function delete(): int
    {
        if ($this->wheres === []) {
            throw new \LogicException('Refusing to delete without a where clause; use a condition that matches every row explicitly.');
        }

        $bindings = [];
        $sql      = $this->grammar->compileDelete($this->model->getTable(), $this->wheres, $bindings);
        $result   = $this->driver()->statement($sql, $bindings);

        return $result === false ? 0 : (int) $result;
    }

    /**
     * Get the database driver.
     */
    protected function driver(): \BooleanSmtp\Core\Database\Drivers\DriverInterface
    {
        return app(\BooleanSmtp\Core\Database\Drivers\DriverInterface::class);
    }

    /**
     * Execute the underlying query.
     *
     * @return Collection<int, Model>
     */
    protected function executeQuery(): Collection
    {
        $bindings = [];

        $sql = $this->grammar->compileSelect(
            $this->model->getTable(),
            $this->wheres,
            $this->orderBy,
            $this->orderDirection,
            $this->limit,
            $this->offset,
            $bindings,
            $this->selects,
            $this->raws,
            $this->distinct,
            $this->joins,
            $this->groups,
            $this->havings
        );

        $rows = $this->driver()->select($sql, $bindings);

        return $this->hydrate($rows);
    }

    /**
     * Load eager loaded relationships onto models.
     *
     * @param Collection<int, Model> $models
     */
    protected function loadRelations(Collection $models): void
    {
        foreach ($this->eagerLoad as $name) {
            if (!method_exists($this->model, $name)) {
                continue;
            }

            $relation = $this->model->$name();

            if (method_exists($relation, 'eagerLoad')) {
                $relation->eagerLoad($models, $name);
            }
        }
    }

    /**
     * Hydrate models from database rows.
     *
     * @param array<array<string, mixed>> $rows
     * @return Collection<int, Model>
     */
    protected function hydrate(array $rows): Collection
    {
        $models = [];

        foreach ($rows as $row) {
            $models[] = $this->model->newFromRow($row);
        }

        return new Collection($models);
    }

    /**
     * Set the limit and offset for a given page.
     */
    public function forPage(int $page, int $perPage = 15): static
    {
        return $this->offset(($page - 1) * $perPage)->limit($perPage);
    }

    /**
     * Process query results in chunks to limit memory usage.
     *
     * The callback receives a Collection of models for each chunk.
     * Return false from the callback to stop processing.
     *
     * @param int $count Number of records per chunk
     * @param callable(Collection<int, Model>, int): mixed $callback
     * @return bool True if all chunks processed, false if stopped early
     */
    public function chunk(int $count, callable $callback): bool
    {
        if ($count <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $page = 1;

        do {
            $clone = clone $this;
            $results = $clone->forPage($page, $count)->get();

            $countResults = $results->count();

            if ($countResults === 0) {
                break;
            }

            if ($callback($results, $page) === false) {
                return false;
            }

            unset($results);

            $page++;
        } while ($countResults === $count);

        return true;
    }

    /**
     * Stream results one at a time using a PHP Generator.
     *
     * Keeps memory footprint constant by fetching in pages internally
     * and yielding one model at a time.
     *
     * @param int $chunkSize Number of records to fetch per internal query
     * @return Generator<int, Model>
     */
    public function cursor(int $chunkSize = 100): Generator
    {
        if ($chunkSize <= 0) {
            throw new \InvalidArgumentException('Chunk size must be greater than zero.');
        }

        $page = 1;

        do {
            $clone = clone $this;
            $results = $clone->forPage($page, $chunkSize)->get();

            $countResults = $results->count();

            foreach ($results as $model) {
                yield $model;
            }

            unset($results);

            $page++;
        } while ($countResults === $chunkSize);
    }

    /**
     * Paginate the query results.
     */
    public function paginate(int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        // Get total count (without limit/offset)
        $total = $this->countTotal();

        // Apply pagination
        $this->limit($perPage);
        $this->offset(($page - 1) * $perPage);

        // Get the items
        $items = $this->get();

        return new LengthAwarePaginator($items, $total, $perPage, $page);
    }

    /**
     * Get the total count without limit/offset.
     */
    protected function countTotal(): int
    {
        $bindings = [];

        $sql = $this->grammar->compileCount(
            $this->model->getTable(),
            $this->wheres,
            $bindings,
            $this->joins
        );

        return (int) $this->driver()->selectVar($sql, $bindings);
    }
}
