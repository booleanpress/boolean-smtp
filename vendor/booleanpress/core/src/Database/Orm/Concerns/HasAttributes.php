<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Database\Orm\Concerns;

/**
 * HasAttributes Trait
 *
 * Provides accessor, mutator, and attribute casting support for models.
 */
trait HasAttributes
{
    /**
     * Get an attribute value with accessor support.
     */
    public function getAttribute(string $key): mixed
    {
        // Check for accessor: getFooAttribute
        $accessor = 'get' . $this->studly($key) . 'Attribute';

        if (method_exists($this, $accessor)) {
            return $this->$accessor($this->attributes[$key] ?? null);
        }

        $value = $this->attributes[$key] ?? null;

        // Apply casting if defined
        if (isset($this->casts[$key])) {
            return $this->castAttribute($key, $value);
        }

        return $value;
    }

    /**
     * Set an attribute value with mutator support.
     */
    public function setAttribute(string $key, mixed $value): static
    {
        // Check for mutator: setFooAttribute
        $mutator = 'set' . $this->studly($key) . 'Attribute';

        if (method_exists($this, $mutator)) {
            $value = $this->$mutator($value);
        }

        // Apply casting for storage
        if (isset($this->casts[$key])) {
            $value = $this->castAttributeForStorage($key, $value);
        }

        $this->attributes[$key] = $value;

        return $this;
    }

    /**
     * Cast an attribute to a native PHP type.
     */
    protected function castAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        $castType = $this->casts[$key] ?? null;

        return match ($castType) {
            'int', 'integer' => (int) $value,
            'real', 'float', 'double' => (float) $value,
            'string' => (string) $value,
            'bool', 'boolean' => (bool) $value,
            'array', 'json' => is_string($value) ? json_decode($value, true) : (array) $value,
            'object' => is_string($value) ? json_decode($value) : (object) $value,
            'date' => $this->asDate($value),
            'datetime' => $this->asDateTime($value),
            'timestamp' => $this->asTimestamp($value),
            default => $value,
        };
    }

    /**
     * Cast an attribute for database storage.
     */
    protected function castAttributeForStorage(string $key, mixed $value): mixed
    {
        $castType = $this->casts[$key] ?? null;

        return match ($castType) {
            'array', 'json', 'object' => is_string($value) ? $value : json_encode($value),
            'bool', 'boolean' => $value ? 1 : 0,
            default => $value,
        };
    }

    /**
     * Convert a value to a date string.
     */
    protected function asDate(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return date('Y-m-d', strtotime((string) $value));
    }

    /**
     * Convert a value to a datetime string.
     */
    protected function asDateTime(mixed $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return date('Y-m-d H:i:s', strtotime((string) $value));
    }

    /**
     * Convert a value to a Unix timestamp.
     */
    protected function asTimestamp(mixed $value): int
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->getTimestamp();
        }
        return strtotime((string) $value);
    }

    /**
     * Convert string to StudlyCase.
     */
    protected function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $value)));
    }

    /**
     * Determine if a get mutator exists for an attribute.
     */
    public function hasGetMutator(string $key): bool
    {
        return method_exists($this, 'get' . $this->studly($key) . 'Attribute');
    }

    /**
     * Determine if a set mutator exists for an attribute.
     */
    public function hasSetMutator(string $key): bool
    {
        return method_exists($this, 'set' . $this->studly($key) . 'Attribute');
    }

    /**
     * Get all castable attributes.
     *
     * @return array<string, string>
     */
    public function getCasts(): array
    {
        return $this->casts ?? [];
    }
}
