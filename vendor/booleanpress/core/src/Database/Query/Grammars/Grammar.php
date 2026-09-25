<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Query\Grammars;

/**
 * Abstract Query Grammar
 *
 * Defines the contract for compiling query builder state into SQL strings.
 * Extend this class for dialect-specific SQL generation (MySQL, SQLite, etc.).
 */
abstract class Grammar
{
    /**
     * Functions `QueryBuilder::orderByRaw()` may order by.
     *
     * @since 0.2.6
     * @var array<string>
     */
    public const ORDER_FUNCTIONS = ['COALESCE', 'IFNULL', 'LOWER', 'UPPER'];

    /**
     * The escape character every `LIKE` the grammar compiles declares. Neither MySQL nor SQLite
     * interprets it inside a string literal, unlike the backslash.
     *
     * @since 0.2.6
     * @var string
     */
    public const LIKE_ESCAPE = '!';

    /**
     * Escape the characters `LIKE` reads as wildcards so a term matches literally.
     *
     * @since 0.2.6
     *
     * @param string $term The text as typed.
     * @return string The text with `!`, `%` and `_` prefixed by the escape character.
     */
    public static function escapeLike(string $term): string
    {
        return str_replace(
            [self::LIKE_ESCAPE, '%', '_'],
            [self::LIKE_ESCAPE . self::LIKE_ESCAPE, self::LIKE_ESCAPE . '%', self::LIKE_ESCAPE . '_'],
            $term
        );
    }

    /**
     * Allowed SQL operators.
     *
     * @var array<string>
     */
    protected array $operators = [
        '=', '!=', '<>', '<', '>', '<=', '>=',
        'LIKE', 'NOT LIKE', 'IN', 'NOT IN',
        'IS', 'IS NOT',
    ];

    /**
     * Compile a SELECT query.
     *
     * @param string $table The full table name (with prefix)
     * @param array<array{column: string, operator: string, value: mixed}> $wheres
     * @param string|array{function: string, columns: list<string>}|null $orderBy A column, or a parsed
     *        expression from `QueryBuilder::orderByRaw()`
     * @param string $orderDirection
     * @param int|null $limit
     * @param int|null $offset
     * @param array<int, mixed> $bindings Populated by reference with binding values
     * @param array<string> $selects Columns to select
     * @param array<array{expression: string, bindings: array}> $raws Raw select expressions
     * @param bool $distinct Whether to use DISTINCT
     * @param array<array{table: string, first: string, operator: string, second: string, type: string}> $joins
     * @param array<string> $groups GROUP BY columns
     * @param array<array{column: string, operator: string, value: mixed}> $havings
     * @return string The compiled SQL
     */
    abstract public function compileSelect(
        string $table,
        array $wheres,
        string|array|null $orderBy,
        string $orderDirection,
        ?int $limit,
        ?int $offset,
        array &$bindings,
        array $selects = [],
        array $raws = [],
        bool $distinct = false,
        array $joins = [],
        array $groups = [],
        array $havings = []
    ): string;

    /**
     * Compile a COUNT query.
     *
     * @param string $table
     * @param array<array{column: string, operator: string, value: mixed}> $wheres
     * @param array<int, mixed> $bindings
     * @param array<array{table: string, first: string, operator: string, second: string, type: string}> $joins
     * @return string
     */
    abstract public function compileCount(
        string $table,
        array $wheres,
        array &$bindings,
        array $joins = []
    ): string;

    /**
     * Compile a DELETE statement for every row the where clauses match.
     *
     * @since 0.2.1
     *
     * @param string $table
     * @param array<array{column: string, operator: string, value: mixed}> $wheres
     * @param array<int, mixed> $bindings
     * @return string
     */
    abstract public function compileDelete(
        string $table,
        array $wheres,
        array &$bindings
    ): string;

    /**
     * Validate a column name to prevent SQL injection.
     */
    public function validateColumnName(string $column): string
    {
        // Allow table.column notation
        if (str_contains($column, '.')) {
            $parts = explode('.', $column, 2);
            $this->validateIdentifier($parts[0]);
            $this->validateIdentifier($parts[1]);
            return $column;
        }

        $this->validateIdentifier($column);
        return $column;
    }

    /**
     * Validate a simple identifier (table or column name).
     */
    protected function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid identifier: %s', $identifier)
            );
        }
    }

    /**
     * Validate a table name to prevent SQL injection.
     */
    public function validateTableName(string $table): string
    {
        $this->validateIdentifier($table);
        return $table;
    }

    /**
     * Validate an operator to prevent SQL injection.
     */
    public function validateOperator(string $operator): string
    {
        $operator = strtoupper(trim($operator));

        if (!in_array($operator, $this->operators, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid SQL operator: %s', $operator)
            );
        }
        return $operator;
    }
}
