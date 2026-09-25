<?php
/**
 * Migration that creates the shared revisions table.
 *
 * @package BooleanSmtp\Core
 * @since   0.2.1
 */

declare(strict_types=1);

use BooleanSmtp\Core\Database\Migration\Migration;
use BooleanSmtp\Core\Database\Schema\Blueprint;
use BooleanSmtp\Core\Database\Schema\Schema;

/**
 * Create Revisions Table
 */
/**
 * Creates the `booleanpress_revisions` table behind the `HasRevisions` model trait. Registered
 * only by plugins that opt in through `Plugin::frameworkMigrations()`.
 *
 * @since 0.2.1
 */
return new class extends Migration
{
    protected string $tableName = 'booleanpress_revisions';
    protected string $action    = 'create';
    protected string $plugin    = 'core';
    protected string $version   = '1.0.0';

    protected array $columns = [
        'id'         => 'bigint',
        'model_type' => 'varchar',
        'model_id'   => 'bigint',
        'data'       => 'longtext',
        'user_id'    => 'bigint',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function up(): void
    {
        Schema::create('booleanpress_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('model_type');
            $table->bigInteger('model_id');
            $table->longText('data');
            $table->bigInteger('user_id')->nullable();
            $table->timestamps();

            $table->index(['model_type', 'model_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('booleanpress_revisions');
    }
};
