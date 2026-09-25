<?php
/**
 * Migration that creates the shared jobs table.
 *
 * @package BooleanSmtp\Core
 * @since   0.2.1
 */

declare(strict_types=1);

use BooleanSmtp\Core\Database\Migration\Migration;
use BooleanSmtp\Core\Database\Schema\Blueprint;
use BooleanSmtp\Core\Database\Schema\Schema;

/**
 * Creates the `booleanpress_jobs` table used by the Queue component's database driver. Registered
 * only by plugins that register the `Queue` component.
 *
 * @since 0.2.1
 */
return new class extends Migration
{
    protected string $tableName = 'booleanpress_jobs';
    protected string $action    = 'create';
    protected string $plugin    = 'core';
    protected string $version   = '1.0.0';

    protected array $columns = [
        'id'                => 'bigint',
        'plugin'            => 'varchar',
        'version'           => 'varchar',
        'queue'             => 'varchar',
        'payload'           => 'longtext',
        'attempts'          => 'tinyint',
        'last_attempted_at' => 'int',
        'status'            => 'varchar',
        'reserved_at'       => 'int',
        'reserved_by'       => 'varchar',
        'available_at'      => 'int',
        'group'             => 'varchar',
        'args'              => 'text',
        'priority'          => 'tinyint',
        'hook'              => 'varchar',
        'created_at'        => 'int',
        'updated_at'        => 'int'
    ];

    public function up(): void
    {
        Schema::create($this->tableName, function (Blueprint $table) {
            $table->id();
            $table->string('plugin', 100)->default('');
            $table->string('version', 20)->default('1.0.0');
            $table->string('queue', 255)->default('default');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('last_attempted_at')->nullable();
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->string('reserved_by', 64)->nullable();
            $table->unsignedInteger('available_at');
            $table->string('group', 100)->nullable();
            $table->text('args')->nullable();
            $table->unsignedTinyInteger('priority')->default(0);
            $table->string('hook', 255)->nullable();
            $table->unsignedInteger('created_at');
            $table->unsignedInteger('updated_at')->nullable();

            $table->index(['queue', 'status']);
            $table->index('plugin');
            $table->index('reserved_at');
            $table->index('reserved_by');
            $table->index('available_at');
            $table->index('priority');
        });

        // Upgrade existing tables that may be missing new columns
        $this->upgradeExistingTable(Schema::tableName($this->tableName));
    }

    /**
     * Add missing columns to existing jobs table (safe upgrade path).
     */
    protected function upgradeExistingTable(string $tableName): void
    {
        $columns = $this->driver->getTableColumns($this->tableName);

        $additions = [
            'version'           => "ADD COLUMN version varchar(20) NOT NULL DEFAULT '1.0.0' AFTER plugin",
            'last_attempted_at' => "ADD COLUMN last_attempted_at int(11) unsigned DEFAULT NULL AFTER attempts",
            'status'            => "ADD COLUMN status varchar(20) NOT NULL DEFAULT 'pending' AFTER last_attempted_at",
            'reserved_by'       => "ADD COLUMN reserved_by varchar(64) DEFAULT NULL AFTER reserved_at",
            'group'             => "ADD COLUMN `group` varchar(100) DEFAULT NULL AFTER available_at",
            'args'              => "ADD COLUMN args text DEFAULT NULL AFTER `group`",
            'priority'          => "ADD COLUMN priority tinyint(3) unsigned NOT NULL DEFAULT 0 AFTER args",
            'hook'              => "ADD COLUMN hook varchar(255) DEFAULT NULL AFTER priority",
            'updated_at'        => "ADD COLUMN updated_at int(11) unsigned DEFAULT NULL AFTER created_at"
        ];

        foreach ($additions as $column => $alterSql) {
            if (!in_array($column, $columns)) {
                $this->driver->statement("ALTER TABLE {$tableName} {$alterSql}");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists($this->tableName);
    }
};
