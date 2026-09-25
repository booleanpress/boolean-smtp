<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Contracts;

use BooleanSmtp\Core\Support\Collection;

/**
 * Repository Contract
 *
 * Provides a standardized interface for data access,
 * making it easier to mock repositories in tests.
 */
interface RepositoryContract
{
    /**
     * Find a model by its primary key.
     *
     * @param int|string $id
     * @return object|null
     */
    public function find(int|string $id): ?object;

    /**
     * Find a model by its primary key or throw an exception.
     *
     * @param int|string $id
     * @return object
     * @throws \BooleanSmtp\Core\Exceptions\ModelNotFoundException
     */
    public function findOrFail(int|string $id): object;

    /**
     * Get all records.
     *
     * @return Collection
     */
    public function all(): Collection;

    /**
     * Create a new record.
     *
     * @param array<string, mixed> $attributes
     * @return object
     */
    public function create(array $attributes): object;

    /**
     * Update an existing record.
     *
     * @param int|string $id
     * @param array<string, mixed> $attributes
     * @return bool
     */
    public function update(int|string $id, array $attributes): bool;

    /**
     * Delete a record.
     *
     * @param int|string $id
     * @return bool
     */
    public function delete(int|string $id): bool;
}
