<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Query\Grammars;

/**
 * MySQL Query Grammar
 *
 * Compiles query builder state into MySQL-compatible SQL strings.
 * Uses %s placeholders compatible with WordPress $wpdb->prepare().
 */
class MySqlGrammar extends Grammar
{
    /**
     * {@inheritdoc}
     */
    public function compileSelect(
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
    ): string {
        $sql = 'SELECT ';

        if ($distinct) {
            $sql .= 'DISTINCT ';
        }

        $sql .= $this->compileColumns($selects, $raws, $bindings);
        $sql .= sprintf(" FROM `%s`", $table);
        $sql .= $this->compileJoins($joins);
        $sql .= $this->compileWheres($wheres, $bindings);
        $sql .= $this->compileGroupBy($groups);
        $sql .= $this->compileHavings($havings, $bindings);
        $sql .= $this->compileOrderBy($orderBy, $orderDirection);
        $sql .= $this->compileLimit($limit);
        $sql .= $this->compileOffset($offset);

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    public function compileCount(
        string $table,
        array $wheres,
        array &$bindings,
        array $joins = []
    ): string {
        $sql = sprintf("SELECT COUNT(*) FROM `%s`", $table);
        $sql .= $this->compileJoins($joins);
        $sql .= $this->compileWheres($wheres, $bindings);

        return $sql;
    }

    /**
     * {@inheritDoc}
     */
    public function compileDelete(
        string $table,
        array $wheres,
        array &$bindings
    ): string {
        return sprintf("DELETE FROM `%s`", $table) . $this->compileWheres($wheres, $bindings);
    }

    /**
     * Compile the SELECT columns portion.
     *
     * @param array<string> $selects
     * @param array<array{expression: string, bindings: array}> $raws
     * @param array<int, mixed> $bindings
     */
    public function compileColumns(array $selects, array $raws, array &$bindings): string
    {
        $parts = [];

        foreach ($selects as $column) {
            if (str_contains($column, '.')) {
                $validated = $this->validateColumnName($column);
                $segmented = explode('.', $validated, 2);
                $parts[] = sprintf('`%s`.`%s`', $segmented[0], $segmented[1]);
            } else {
                $parts[] = sprintf('`%s`', $this->validateColumnName($column));
            }
        }

        foreach ($raws as $raw) {
            $parts[] = $raw['expression'];
            foreach ($raw['bindings'] as $binding) {
                $bindings[] = $binding;
            }
        }

        if (empty($parts)) {
            return '*';
        }

        return implode(', ', $parts);
    }

    /**
     * Compile JOIN clauses into SQL.
     *
     * @param array<array{table: string, first: string, operator: string, second: string, type: string}> $joins
     */
    public function compileJoins(array $joins): string
    {
        if (empty($joins)) {
            return '';
        }

        $sql = '';

        foreach ($joins as $join) {
            $type = strtoupper($join['type']);
            $table = $join['table'];
            $first = $this->validateColumnName($join['first']);
            $operator = $this->validateOperator($join['operator']);
            $second = $this->validateColumnName($join['second']);

            // Format column references with backticks
            $firstFormatted = $this->quoteColumnRef($first);
            $secondFormatted = $this->quoteColumnRef($second);

            $sql .= sprintf(
                " %s JOIN `%s` ON %s %s %s",
                $type,
                $table,
                $firstFormatted,
                $operator,
                $secondFormatted
            );
        }

        return $sql;
    }

    /**
     * Quote a column reference (handles table.column notation).
     */
    protected function quoteColumnRef(string $column): string
    {
        if (str_contains($column, '.')) {
            $parts = explode('.', $column, 2);
            return sprintf('`%s`.`%s`', $parts[0], $parts[1]);
        }

        return sprintf('`%s`', $column);
    }

    /**
     * Compile WHERE clauses into SQL.
     *
     * @param array<array{column?: string, operator?: string, value?: mixed, boolean?: string, type?: string, values?: array}> $wheres
     * @param array<int, mixed> $bindings
     */
    public function compileWheres(array $wheres, array &$bindings): string
    {
        if (empty($wheres)) {
            return '';
        }

        return ' WHERE ' . $this->compileWhereParts($wheres, $bindings);
    }

    /**
     * Compile where clauses into their joined SQL, without the `WHERE` keyword, so a nested
     * group can wrap the same output in parentheses.
     *
     * @since 0.2.6
     *
     * @param array<int, array<string, mixed>> $wheres
     * @param array<int, mixed> $bindings
     */
    protected function compileWhereParts(array $wheres, array &$bindings): string
    {
        $whereParts = [];

        foreach (array_values($wheres) as $index => $where) {
            $type = $where['type'] ?? 'basic';
            $boolean = strtoupper($where['boolean'] ?? 'and');
            $part = '';

            switch ($type) {
                case 'nested':
                    $inner = is_array($where['wheres'] ?? null) ? $where['wheres'] : [];
                    if ($inner === []) {
                        continue 2;
                    }
                    $part = '(' . $this->compileWhereParts($inner, $bindings) . ')';
                    break;

                case 'like':
                    $column = $this->validateColumnName($where['column']);
                    $part = sprintf("`%s` LIKE %%s ESCAPE '%s'", $column, self::LIKE_ESCAPE);
                    $bindings[] = '%' . self::escapeLike((string) $where['value']) . '%';
                    break;

                case 'null':
                    $column = $this->validateColumnName($where['column']);
                    $part = sprintf("`%s` IS NULL", $column);
                    break;

                case 'not_null':
                    $column = $this->validateColumnName($where['column']);
                    $part = sprintf("`%s` IS NOT NULL", $column);
                    break;

                case 'between':
                    $column = $this->validateColumnName($where['column']);
                    $part = sprintf("`%s` BETWEEN %%s AND %%s", $column);
                    $bindings[] = $where['values'][0];
                    $bindings[] = $where['values'][1];
                    break;

                default:
                    $column = $this->validateColumnName($where['column']);
                    $operator = $this->validateOperator($where['operator']);

                    if ($operator === 'IN' || $operator === 'NOT IN') {
                        $inValues = is_array($where['value']) ? $where['value'] : [];

                        if (empty($inValues)) {
                            $part = $operator === 'IN' ? '1 = 0' : '1 = 1';
                            break;
                        }

                        $placeholders = implode(',', array_fill(0, count($inValues), '%s'));
                        $part = sprintf("`%s` %s (%s)", $column, $operator, $placeholders);

                        foreach ($inValues as $value) {
                            $bindings[] = $value;
                        }

                        break;
                    }

                    $part = sprintf("`%s` %s %%s", $column, $operator);
                    $bindings[] = $where['value'];
                    break;
            }

            // First clause never gets a boolean connector
            if ($whereParts === []) {
                $whereParts[] = $part;
            } else {
                $whereParts[] = $boolean . ' ' . $part;
            }
        }

        return implode(' ', $whereParts);
    }

    /**
     * Compile GROUP BY clause.
     *
     * @param array<string> $groups
     */
    public function compileGroupBy(array $groups): string
    {
        if (empty($groups)) {
            return '';
        }

        $validated = [];
        foreach ($groups as $column) {
            $validated[] = sprintf('`%s`', $this->validateColumnName($column));
        }

        return ' GROUP BY ' . implode(', ', $validated);
    }

    /**
     * Compile HAVING clauses into SQL.
     *
     * @param array<array{column: string, operator: string, value: mixed}> $havings
     * @param array<int, mixed> $bindings
     */
    public function compileHavings(array $havings, array &$bindings): string
    {
        if (empty($havings)) {
            return '';
        }

        $parts = [];

        foreach ($havings as $having) {
            $column = $this->validateColumnName($having['column']);
            $operator = $this->validateOperator($having['operator']);
            $parts[] = sprintf("`%s` %s %%s", $column, $operator);
            $bindings[] = $having['value'];
        }

        return ' HAVING ' . implode(' AND ', $parts);
    }

    /**
     * Compile ORDER BY clause.
     *
     * @since 0.2.6 Accepts the parsed expression `QueryBuilder::orderByRaw()` produces.
     *
     * @param string|array{function: string, columns: list<string>}|null $orderBy
     * @param string $orderDirection
     */
    public function compileOrderBy(string|array|null $orderBy, string $orderDirection): string
    {
        if ($orderBy === null) {
            return '';
        }

        $direction = strtoupper($orderDirection) === 'DESC' ? 'DESC' : 'ASC';

        if (is_array($orderBy)) {
            $function = strtoupper($orderBy['function']);
            if (!in_array($function, self::ORDER_FUNCTIONS, true)) {
                throw new \InvalidArgumentException(sprintf('Unsupported order function: %s', $function));
            }

            $columns = [];
            foreach ($orderBy['columns'] as $column) {
                $columns[] = $this->quoteColumnRef($this->validateColumnName($column));
            }
            if ($columns === []) {
                throw new \InvalidArgumentException('An order expression needs at least one column.');
            }

            return sprintf(' ORDER BY %s(%s) %s', $function, implode(', ', $columns), $direction);
        }

        $column = $this->validateColumnName($orderBy);

        return sprintf(" ORDER BY `%s` %s", $column, $direction);
    }

    /**
     * Compile LIMIT clause.
     */
    public function compileLimit(?int $limit): string
    {
        if ($limit === null) {
            return '';
        }

        return sprintf(" LIMIT %d", $limit);
    }

    /**
     * Compile OFFSET clause.
     */
    public function compileOffset(?int $offset): string
    {
        if ($offset === null) {
            return '';
        }

        return sprintf(" OFFSET %d", $offset);
    }
}
