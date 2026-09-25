<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Validation;

use BooleanSmtp\Core\Support\Arr;
use BooleanSmtp\Core\Support\Str;
use function BooleanSmtp\Core\app;

class Validator
{
    protected array $data;
    protected array $rules;
    protected array $errors = [];

    /**
     * Rules of the field currently being validated (lets min/max know about integer/numeric).
     *
     * @var array<int, string>
     */
    protected array $currentRules = [];

    public function __construct(array $data, array $rules)
    {
        $this->data = $data;
        $this->rules = $rules;
    }

    public static function make(array $data, array $rules): static
    {
        return new static($data, $rules);
    }

    public function fails(): bool
    {
        $this->validate();
        return !empty($this->errors);
    }

    public function errors(): array
    {
        return $this->errors;
    }

    public function validated(): array
    {
        $this->validate();

        // Return only the data that was validated; dotted keys come back nested.
        $validated = [];
        foreach ($this->rules as $field => $rule) {
            if (str_contains($field, '.*')) {
                continue;
            }

            if (str_contains($field, '.')) {
                if (Arr::has($this->data, $field)) {
                    Arr::set($validated, $field, Arr::get($this->data, $field));
                }
                continue;
            }

            if (array_key_exists($field, $this->data)) {
                $validated[$field] = $this->data[$field];
            }
        }

        return $validated;
    }

    protected function addError(string $field, string $rule, array $params): void
    {
        $message = $this->getErrorMessage($field, $rule, $params);
        $this->errors[$field][] = $message;
    }

    // Rules

    protected function validateRequired(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return false;
        }
        if (is_string($value) && trim($value) === '') {
            return false;
        }
        if (is_array($value) && count($value) === 0) {
            return false;
        }
        return true;
    }

    protected function validateString(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true; // generic "nullable" behavior if not required
        }
        return is_string($value);
    }

    protected function validateInteger(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_INT) !== false;
    }

    protected function validateBoolean(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return in_array($value, [true, false, 0, 1, '0', '1'], true);
    }

    protected function validateArray(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return is_array($value);
    }

    protected function validateEmail(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
    }

    protected function validateMin(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        $min = (int) ($params[0] ?? 0);

        // Under an integer/numeric rule a numeric string is compared as a number, not by length.
        if ($this->isNumericField() && is_numeric($value)) {
            return (float) $value >= $min;
        }

        if (is_string($value)) {
            return mb_strlen($value) >= $min;
        }
        if (is_int($value) || is_float($value)) {
            return $value >= $min;
        }
        if (is_array($value)) {
            return count($value) >= $min;
        }
        return true;
    }

    protected function validateMax(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        $max = (int) ($params[0] ?? 0);

        if ($this->isNumericField() && is_numeric($value)) {
            return (float) $value <= $max;
        }

        if (is_string($value)) {
            return mb_strlen($value) <= $max;
        }
        if (is_int($value) || is_float($value)) {
            return $value <= $max;
        }
        if (is_array($value)) {
            return count($value) <= $max;
        }
        return true;
    }

    protected function validateIn(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return in_array((string)$value, $params, false);
    }

    // ============= NEW VALIDATION RULES =============

    /**
     * Validate unique value in database.
     * Usage: unique:table,column,except_id
     */
    protected function validateUnique(string $field, mixed $value, array $params): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $driver = app(\BooleanSmtp\Core\Database\Drivers\DriverInterface::class);

        $tableName = $params[0] ?? $field;
        $table = $driver->getTable($tableName);
        $column = $params[1] ?? $field;
        $exceptId = $params[2] ?? null;

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %s";
        $bindings = [$value];

        if ($exceptId !== null) {
            $sql .= " AND id != %d";
            $bindings[] = $exceptId;
        }

        $count = (int) $driver->selectVar($sql, $bindings);

        return $count === 0;
    }

    /**
     * Validate value exists in database.
     * Usage: exists:table,column
     */
    protected function validateExists(string $field, mixed $value, array $params): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $driver = app(\BooleanSmtp\Core\Database\Drivers\DriverInterface::class);

        $tableName = $params[0] ?? $field;
        $table = $driver->getTable($tableName);
        $column = $params[1] ?? 'id';

        $sql = "SELECT COUNT(*) FROM `{$table}` WHERE `{$column}` = %s";

        $count = (int) $driver->selectVar($sql, [$value]);

        return $count > 0;
    }

    /**
     * Validate value matches a regular expression.
     * Usage: regex:/pattern/
     */
    protected function validateRegex(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }

        $pattern = $params[0] ?? '';

        return preg_match($pattern, (string) $value) === 1;
    }

    /**
     * Validate value is a valid date.
     */
    protected function validateDate(string $field, mixed $value, array $params): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        return strtotime((string) $value) !== false;
    }

    /**
     * Validate value matches another field (e.g., password_confirmation).
     * Usage: confirmed
     */
    protected function validateConfirmed(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }

        $confirmationField = $field . '_confirmation';
        $confirmationValue = $this->data[$confirmationField] ?? null;

        return $value === $confirmationValue;
    }

    /**
     * Validate value is numeric.
     */
    protected function validateNumeric(string $field, mixed $value, array $params): bool
    {
        if (is_null($value)) {
            return true;
        }
        return is_numeric($value);
    }

    /**
     * Validate value is a URL.
     */
    protected function validateUrl(string $field, mixed $value, array $params): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }
        return filter_var($value, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Whether the field being validated also carries an integer or numeric rule.
     */
    protected function isNumericField(): bool
    {
        foreach ($this->currentRules as $rule) {
            if ($rule === 'integer' || $rule === 'numeric') {
                return true;
            }
        }

        return false;
    }

    /**
     * Validate value is nullable (allows null).
     */
    protected function validateNullable(string $field, mixed $value, array $params): bool
    {
        return true; // Always passes, just indicates null is allowed
    }

    /**
     * Validate value matches date format.
     * Usage: date_format:Y-m-d
     */
    protected function validateDateFormat(string $field, mixed $value, array $params): bool
    {
        if (is_null($value) || $value === '') {
            return true;
        }

        $format = $params[0] ?? 'Y-m-d';
        $date = \DateTime::createFromFormat($format, (string) $value);

        return $date && $date->format($format) === $value;
    }

    /**
     * Get enhanced error messages including new rules.
     */
    protected function getErrorMessage(string $field, string $rule, array $params): string
    {
        $messages = [
            'required' => ':field is required.',
            'string' => ':field must be a string.',
            'integer' => ':field must be an integer.',
            'boolean' => ':field must be a boolean.',
            'array' => ':field must be an array.',
            'email' => ':field must be a valid email address.',
            'min' => ':field must be at least :min.',
            'max' => ':field must not be greater than :max.',
            'in' => ':field must be one of: :values.',
            'unique' => ':field has already been taken.',
            'exists' => ':field does not exist.',
            'regex' => ':field format is invalid.',
            'date' => ':field must be a valid date.',
            'confirmed' => ':field confirmation does not match.',
            'numeric' => ':field must be a number.',
            'url' => ':field must be a valid URL.',
            'date_format' => ':field does not match the format :format.',
        ];

        $message = $messages[$rule] ?? ":field has an invalid value.";
        $message = str_replace(':field', str_replace('_', ' ', $field), $message);

        if ($rule === 'min' || $rule === 'max') {
            $message = str_replace(":$rule", $params[0] ?? '', $message);
        }

        if ($rule === 'in') {
            $message = str_replace(":values", implode(', ', $params), $message);
        }

        if ($rule === 'date_format') {
            $message = str_replace(":format", $params[0] ?? '', $message);
        }

        return $message;
    }

    // ============= BATCH VALIDATION =============

    /**
     * Validate uniqueness for multiple values in a single query.
     *
     * Instead of running one query per row (N+1), this uses a single
     * WHERE IN query to check all values at once.
     *
     * @param string $table  Table name (without prefix)
     * @param string $column Column to check uniqueness on
     * @param array<int, mixed> $values Values to check
     * @param int|string|null $exceptId Optional ID to exclude (for updates)
     * @return array<int, mixed> Values that already exist in the database
     */
    public static function validateUniqueBatch(
        string $table,
        string $column,
        array $values,
        int|string|null $exceptId = null
    ): array {
        if (empty($values)) {
            return [];
        }

        // Remove nulls and empty strings
        $values = array_filter($values, fn (mixed $v): bool => $v !== null && $v !== '');
        $values = array_values($values);

        if (empty($values)) {
            return [];
        }

        $driver = app(\BooleanSmtp\Core\Database\Drivers\DriverInterface::class);
        $fullTable = $driver->getTable($table);

        // Validate column name to prevent SQL injection
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $column)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid column name: %s', $column)
            );
        }

        $placeholders = implode(',', array_fill(0, count($values), '%s'));
        $sql = sprintf(
            "SELECT `%s` FROM `%s` WHERE `%s` IN (%s)",
            $column,
            $fullTable,
            $column,
            $placeholders
        );
        $bindings = $values;

        if ($exceptId !== null) {
            $sql .= " AND id != %s";
            $bindings[] = $exceptId;
        }

        $rows = $driver->select($sql, $bindings);

        return array_map(
            fn (array $row): mixed => $row[$column] ?? null,
            $rows
        );
    }

    // ============= ARRAY VALIDATION SUPPORT =============

    /**
     * Validate with array notation support (e.g., items.*.name).
     */
    protected function validate(): void
    {
        $this->errors = [];

        foreach ($this->rules as $field => $rules) {
            if (is_string($rules)) {
                $rules = explode('|', $rules);
            }

            // Check for array wildcard notation
            if (str_contains($field, '.*')) {
                $this->validateArrayField($field, $rules);
                continue;
            }

            // Dotted keys ("settings.host") read the nested value.
            $value = str_contains($field, '.') ? Arr::get($this->data, $field) : ($this->data[$field] ?? null);

            // A nullable field that is absent, null or an empty string passes without running
            // the remaining rules (an empty "from_email" box is not an invalid email).
            if (in_array('nullable', $rules, true) && ($value === null || $value === '')) {
                continue;
            }

            $this->currentRules = $rules;

            foreach ($rules as $rule) {
                $params = [];

                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $method = 'validate' . Str::studly($rule);

                if (!method_exists($this, $method)) {
                    throw new \InvalidArgumentException("Validation rule '{$rule}' is not defined.");
                }

                if (!$this->$method($field, $value, $params)) {
                    $this->addError($field, $rule, $params);
                    if (in_array('bail', $rules)) {
                        break;
                    }
                }
            }
        }
    }

    /**
     * Validate array fields with wildcard notation.
     */
    protected function validateArrayField(string $field, array $rules): void
    {
        // Parse field path: items.*.name -> [items, *, name]
        $parts = explode('.', $field);
        $baseField = array_shift($parts); // 'items'

        $baseData = $this->data[$baseField] ?? [];

        if (!is_array($baseData)) {
            return;
        }

        foreach ($baseData as $index => $item) {
            $fieldPath = $baseField . '.' . $index;

            // Get nested value
            $value = $item;
            $remainingParts = array_slice($parts, 1); // Remove the '*'

            foreach ($remainingParts as $part) {
                if (is_array($value) && isset($value[$part])) {
                    $value = $value[$part];
                    $fieldPath .= '.' . $part;
                } else {
                    $value = null;
                    break;
                }
            }

            // Validate
            foreach ($rules as $rule) {
                $params = [];

                if (str_contains($rule, ':')) {
                    [$rule, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                }

                $method = 'validate' . Str::studly($rule);

                if (!method_exists($this, $method)) {
                    throw new \InvalidArgumentException("Validation rule '{$rule}' is not defined.");
                }

                if (!$this->$method($fieldPath, $value, $params)) {
                    $this->addError($fieldPath, $rule, $params);
                }
            }
        }
    }
}
