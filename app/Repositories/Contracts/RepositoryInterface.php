<?php
/**
 * Base contract every repository implements.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

namespace BooleanSmtp\Repositories\Contracts;

/**
 * Abstracts database access for a domain entity behind a consistent CRUD-style interface.
 *
 * Implementations must not leak persistence details (query builders, raw SQL) to callers;
 * every method takes and returns plain values or domain entities.
 *
 * @since 1.0.0
 */
interface RepositoryInterface {
    /**
     * Find an entity by its identifier.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Entity identifier.
     * @return mixed The entity, or null when no entity matches the identifier.
     */
    public function find(string | int $id): mixed;

    /**
     * Get every entity matching the given filters.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Implementation-specific filter criteria.
     * @return array<int, mixed> The matching entities.
     */
    public function all(array $filters = []): array;

    /**
     * Create a new entity.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Attributes for the new entity.
     * @return mixed The created entity.
     */
    public function create(array $data): mixed;

    /**
     * Update an existing entity.
     *
     * @since 1.0.0
     *
     * @param  string|int            $id   Entity identifier.
     * @param  array<string, mixed>  $data Attributes to update.
     * @return mixed The updated entity.
     */
    public function update(string | int $id, array $data): mixed;

    /**
     * Delete an entity.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Entity identifier.
     * @return bool True when the entity was deleted.
     */
    public function delete(string | int $id): bool;

    /**
     * Check whether an entity with the given identifier exists.
     *
     * @since 1.0.0
     *
     * @param  string|int $id Entity identifier.
     * @return bool True when the entity exists.
     */
    public function exists(string | int $id): bool;

    /**
     * Count the entities matching the given filters.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $filters Implementation-specific filter criteria.
     * @return int The number of matching entities.
     */
    public function count(array $filters = []): int;
}
