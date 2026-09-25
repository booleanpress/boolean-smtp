<?php
/**
 * Data access for mailer connections: listing, CRUD, and health/priority queries.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Repositories;

use BooleanSmtp\Models\Connection;
use BooleanSmtp\Core\Support\Collection;
use BooleanSmtp\Core\Database\Drivers\DriverInterface;
use function BooleanSmtp\Core\app;

/**
 * Queries and manages {@see Connection} records.
 *
 * @since 1.0.0
 */
class ConnectionRepository
{
    /**
     * Get every connection, most recently created first.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Connection>
     */
    public function all(): Collection
    {
        return Connection::query()->orderBy('id', 'DESC')->get();
    }

    /**
     * Get every active connection, most recently created first.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Connection>
     */
    public function active(): Collection
    {
        return Connection::query()
            ->where('is_active', true)
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Every active connection in the order the retry ladder walks them: by priority, then by id.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Connection>
     */
    public function activeByPriority(): Collection
    {
        $active = Connection::query()
            ->where('is_active', true)
            ->orderBy('id', 'ASC')
            ->get()
            ->all();

        // A stable sort by priority: equal priorities keep their id order.
        usort($active, static fn (Connection $a, Connection $b): int => [(int) $a->priority, (int) $a->id] <=> [(int) $b->priority, (int) $b->id]);

        return new Collection($active);
    }

    /**
     * Find a connection by ID.
     *
     * @since 1.0.0
     *
     * @param  int $id Connection ID.
     * @return Connection|null The connection, or null when no connection matches the ID.
     */
    public function find(int $id): ?Connection
    {
        return Connection::find($id);
    }

    /**
     * Find a connection by ID, or throw when it does not exist.
     *
     * @since 1.0.0
     *
     * @param  int $id Connection ID.
     * @return Connection
     *
     * @throws \RuntimeException When no connection matches the ID.
     */
    public function findOrFail(int $id): Connection
    {
        return Connection::findOrFail($id);
    }

    /**
     * Create a new connection.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Connection attributes.
     * @return Connection The created connection.
     *
     * @throws \RuntimeException When the connection could not be saved.
     */
    public function create(array $data): Connection
    {
        $connection = new Connection($data);
        $ok = $connection->save();

        if ($ok === false) {
            /** @var DriverInterface $driver */
            $driver = app(DriverInterface::class);
            throw new \RuntimeException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
                'Failed to create connection: ' . ($driver->lastError() ?: 'unknown error')
            );
        }

        $this->announceSaved($connection, true);

        return $connection;
    }

    /**
     * Update a connection's attributes.
     *
     * @since 1.0.0
     *
     * @param  int                   $id   Connection ID.
     * @param  array<string, mixed>  $data Attributes to update.
     * @return Connection The updated connection.
     *
     * @throws \RuntimeException When no connection matches the ID, or the update fails.
     */
    public function update(int $id, array $data): Connection
    {
        $connection = $this->findOrFail($id);

        $ok = $connection->update($data);
        if ($ok === false) {
            /** @var DriverInterface $driver */
            $driver = app(DriverInterface::class);
            throw new \RuntimeException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
                'Failed to update connection: ' . ($driver->lastError() ?: 'unknown error')
            );
        }

        $this->announceSaved($connection, false);

        return $connection;
    }

    /**
     * Announce a saved connection to the `boolean_smtp_connection_saved` listeners.
     *
     * @since 1.0.0
     *
     * @param Connection $connection The connection as stored.
     * @param bool       $created    True for a new row, false for an update.
     */
    protected function announceSaved(Connection $connection, bool $created): void
    {
        if (!\function_exists('do_action')) {
            return;
        }

        /**
         * Fires after a connection has been written to the database, wherever the write came from.
         *
         * Fires for the admin UI, the settings importer, the migration from another plugin, the
         * Pro one-click flows and the OAuth token refresh alike — every path goes through the
         * connection repository. `boolean_smtp_connection_created` and
         * `boolean_smtp_connection_updated` are the admin UI's own events and fire only there.
         * The connection's credentials are encrypted at rest; decrypt `$connection->settings`
         * through the encryptor contract if you need them.
         *
         * @since 1.0.0
         *
         * @param \BooleanSmtp\Models\Connection $connection The connection as stored.
         * @param bool                            $created    True when the row was just created, false when it was updated.
         */
        \do_action('boolean_smtp_connection_saved', $connection, $created);
    }

    /**
     * Delete a connection.
     *
     * @since 1.0.0
     *
     * @param  int $id Connection ID.
     *
     * @throws \RuntimeException When no connection matches the ID, or the delete fails.
     */
    public function delete(int $id): void
    {
        $connection = $this->findOrFail($id);

        $ok = $connection->delete();
        if ($ok === false) {
            /** @var DriverInterface $driver */
            $driver = app(DriverInterface::class);
            throw new \RuntimeException(
                // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
                'Failed to delete connection: ' . ($driver->lastError() ?: 'unknown error')
            );
        }
    }

    /**
     * Get the highest-ID active connection.
     *
     * Used as the fallback default connection when no explicit default is configured.
     *
     * @since 1.0.0
     *
     * @return Connection|null The primary connection, or null when there is no active connection.
     */
    public function getPrimary(): ?Connection
    {
        return Connection::query()
            ->where('is_active', true)
            ->orderBy('id', 'DESC')
            ->first();
    }

    /**
     * Resolve the connection to use when no routing rule applies.
     *
     * Prefers the explicit default connection when one is given and still active;
     * otherwise falls back to {@see getPrimary()} (the legacy highest-ID active
     * connection behavior, kept for installs that predate the default-connection setting).
     *
     * @since 1.0.0
     *
     * @param  int|null $defaultConnectionId ID of the configured default connection, if any.
     * @return Connection|null The resolved connection, or null when there is no active connection.
     */
    public function getDefaultOrPrimary(?int $defaultConnectionId = null): ?Connection
    {
        if ($defaultConnectionId !== null && $defaultConnectionId > 0) {
            $connection = $this->find($defaultConnectionId);
            if ($connection && $connection->is_active) {
                return $connection;
            }
        }

        return $this->getPrimary();
    }

    /**
     * Get every active connection eligible to receive a fallback retry, most recently
     * created first.
     *
     * @since 1.0.0
     *
     * @return Collection<int, Connection>
     */
    public function getFallbacks(): Collection
    {
        return Connection::query()
            ->where('is_active', true)
            ->orderBy('id', 'DESC')
            ->get();
    }

    /**
     * Record a connection's health check outcome.
     *
     * Also stamps `last_used_at` with the current UTC time.
     *
     * @since 1.0.0
     *
     * @param  int         $id     Connection ID.
     * @param  string      $status Health status to record.
     * @param  string|null $error  Error message from the health check, if any.
     *
     * @throws \RuntimeException When no connection matches the ID.
     */
    public function updateHealthStatus(int $id, string $status, ?string $error = null): void
    {
        $connection = $this->findOrFail($id);
        $connection->update([
            'health_status' => $status,
            'last_error'    => $error,
            'last_used_at'  => gmdate('Y-m-d H:i:s'),
        ]);
    }
}
