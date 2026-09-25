<?php
/**
 * Migration that creates the connections table.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

defined('ABSPATH') || exit;

use BooleanSmtp\Core\Database\Migration\Migration;
use BooleanSmtp\Core\Database\Schema\Schema;
use BooleanSmtp\Core\Database\Schema\Blueprint;

/**
 * Creates the `boolean_smtp_connections` table, which stores every configured mail provider
 * connection together with its OAuth token refresh history (a capped JSON list).
 *
 * @since 1.0.0
 */
return new class extends Migration
{
    /**
     * Target table name, without the site's table prefix.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $tableName = 'boolean_smtp_connections';

    /**
     * Migration action type.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $action    = 'create';

    /**
     * Plugin identifier this migration belongs to.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $plugin    = 'boolean-smtp';

    /**
     * Plugin version this migration was introduced in.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $version   = '1.0.0';

    /**
     * Columns created by this migration, keyed by column name.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    protected array $columns = [
        'id'            => 'bigint',
        'name'          => 'varchar',
        'driver'        => 'varchar',
        'settings'      => 'longtext',
        'is_active'     => 'tinyint',
        'priority'      => 'int',
        'health_status' => 'varchar',
        'last_error'    => 'varchar',
        'last_used_at'  => 'timestamp',
        'oauth_refresh_history' => 'longtext',
        'created_at'    => 'timestamp',
        'updated_at'    => 'timestamp',
    ];

    /**
     * Create the connections table.
     *
     * Creates `boolean_smtp_connections` with columns for the connection's identity (name,
     * driver), its settings (stored as JSON in a `longtext` column), activation and priority,
     * health status, and the standard timestamp columns, with indexes on driver, is_active and
     * priority to support connection routing lookups.
     *
     * @since 1.0.0
     */
    public function up(): void
    {
        Schema::create('boolean_smtp_connections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver', 50)->default('smtp');
            $table->longText('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('priority')->default(0);
            $table->string('health_status', 50)->default('unknown');
            $table->string('last_error')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->longText('oauth_refresh_history')->nullable();
            $table->timestamps();

            $table->index('driver');
            $table->index('is_active');
            $table->index('priority');
        });
    }

    /**
     * Drop the connections table.
     *
     * Reverses up() by dropping `boolean_smtp_connections` entirely, if it exists.
     *
     * @since 1.0.0
     */
    public function down(): void
    {
        Schema::dropIfExists('boolean_smtp_connections');
    }
};
