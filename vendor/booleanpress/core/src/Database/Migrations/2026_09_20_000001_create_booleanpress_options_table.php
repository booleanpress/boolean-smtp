<?php
/**
 * Migration that creates the shared options table.
 *
 * @package BooleanSmtp\Core
 * @since   0.2.1
 */

declare(strict_types=1);

use BooleanSmtp\Core\Database\Migration\Migration;
use BooleanSmtp\Core\Database\Schema\Schema;
use BooleanSmtp\Core\Database\Schema\Blueprint;

/**
 * Creates the `booleanpress_options` table: every BooleanPress plugin's settings, transients and
 * small bounded datasets, scoped by the `plugin` column. Registered by every plugin through
 * `Plugin::frameworkMigrations()`.
 *
 * @since 0.2.1
 */
return new class extends Migration
{
    protected string $tableName = 'booleanpress_options';
    protected string $action    = 'create';
    protected string $plugin    = 'core';
    protected string $version   = '1.0.0';

    protected array $columns = [
        'id'           => 'bigint',
        'plugin'       => 'varchar',
        'version'      => 'varchar',
        'option_name'  => 'varchar',
        'option_value' => 'longtext',
        'autoload'     => 'varchar',
        'type'         => 'varchar',
        'ref_id'       => 'bigint',
        'expires_at'   => 'datetime',
        'created_at'   => 'timestamp',
        'updated_at'   => 'timestamp',
    ];

    public function up(): void
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->id();
            $table->string('plugin', 100);
            $table->string('version', 20)->default('1.0.0');
            $table->string('option_name', 191);
            $table->longText('option_value')->nullable();
            $table->string('autoload', 20)->default('yes');
            $table->string('type', 50)->default('setting');
            $table->unsignedBigInteger('ref_id')->default(0);
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['plugin', 'option_name', 'ref_id']);
            $table->index('autoload');
            $table->index('type');
            $table->index('ref_id');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName);
    }
};
