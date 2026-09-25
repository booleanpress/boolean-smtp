<?php

/**
 * Applies the migration connection filter before an imported connection is persisted.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

/**
 * Applies {@see 'boolean_smtp_migration_connection_data'} before persisting an imported connection.
 *
 * @since 1.0.0
 */
final class MigrationConnectionAttributes
{
    /**
     * Filters the attribute array for a connection about to be imported.
     *
     * @since 1.0.0
     *
     * @param  string                $source     Identifier of the plugin the connection was imported from.
     * @param  array<string, mixed>  $attributes Connection attributes about to be persisted.
     * @return array<string, mixed>
     */
    public static function filtered(string $source, array $attributes): array
    {
        /**
         * Filters the attributes of a connection imported from another plugin before it is persisted.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $attributes Connection attributes about to be persisted.
         * @param string               $source     Identifier of the plugin the connection was imported from.
         * @return array<string, mixed> The filtered attributes.
         */
        return \apply_filters('boolean_smtp_migration_connection_data', $attributes, $source);
    }
}
