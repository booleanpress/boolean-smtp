<?php
/**
 * Migration that creates the email logs table.
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
 * Creates the `boolean_smtp_email_logs` table, which records every outgoing email attempt and its
 * delivery outcome.
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
    protected string $tableName = 'boolean_smtp_email_logs';

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
        'id'                => 'bigint',
        'to'                => 'varchar',
        'cc'                => 'text',
        'bcc'               => 'text',
        'from_email'        => 'varchar',
        'from_name'         => 'varchar',
        'subject'           => 'varchar',
        'body'              => 'longtext',
        'headers'           => 'longtext',
        'status'            => 'varchar',
        'provider'          => 'varchar',
        'connection_id'     => 'bigint',
        'error_message'     => 'text',
        'message_id'        => 'varchar',
        'retries'           => 'int',
        'delivery_time_ms'  => 'int',
        'attempts'          => 'longtext',
        'attachments'       => 'longtext',
        'source'            => 'varchar',
        'backtrace'         => 'longtext',
        'last_attempted_at' => 'datetime',
        'next_attempt_at'   => 'datetime',
        'reserved_by'       => 'varchar',
        'reserved_at'       => 'int',
        'created_at'        => 'timestamp',
        'updated_at'        => 'timestamp',
    ];

    /**
     * Create the email logs table.
     *
     * Creates `boolean_smtp_email_logs` with columns for the message envelope (to, cc, bcc,
     * from_email, from_name, subject, body), delivery bookkeeping (status, provider,
     * connection_id, error_message, message_id, retries, delivery_time_ms), JSON columns for
     * attempts/attachments/backtrace (stored as text and decoded in PHP), the retry schedule
     * (next_attempt_at: the UTC time from which the queue worker may retry a failed message on another
     * connection, `NULL` when the row is not waiting for a retry), queue reservation columns
     * (reserved_by, reserved_at), and the standard timestamp columns, with indexes to support status
     * filtering, connection and provider lookups, and queue processing. `message_id` is 191 characters
     * so its index fits the 767-byte limit of utf8mb4 on MySQL 5.5/5.6.
     *
     * @since 1.0.0
     */
    public function up(): void
    {
        Schema::create('boolean_smtp_email_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to');
            $table->text('cc')->nullable();
            $table->text('bcc')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->string('subject');
            $table->longText('body')->nullable();
            $table->json('headers')->nullable();
            $table->string('status', 50)->default('pending');
            $table->string('provider', 50)->nullable();
            $table->unsignedBigInteger('connection_id')->nullable();
            $table->text('error_message')->nullable();
            $table->string('message_id', 191)->nullable();
            $table->integer('retries')->default(0);
            $table->integer('delivery_time_ms')->nullable();
            $table->json('attempts')->nullable();
            $table->json('attachments')->nullable();
            $table->string('source')->nullable();
            $table->json('backtrace')->nullable();
            $table->datetime('last_attempted_at')->nullable();
            $table->datetime('next_attempt_at')->nullable();
            $table->string('reserved_by', 50)->nullable();
            $table->unsignedInteger('reserved_at')->nullable();
            $table->timestamps();

            $table->index('status');
            $table->index('connection_id');
            $table->index('provider');
            $table->index('created_at');
            $table->index('message_id');
            $table->index('last_attempted_at');
            $table->index('reserved_at');
            $table->index('next_attempt_at');
        });
    }

    /**
     * Drop the email logs table.
     *
     * Reverses up() by dropping `boolean_smtp_email_logs` entirely, if it exists.
     *
     * @since 1.0.0
     */
    public function down(): void
    {
        Schema::dropIfExists('boolean_smtp_email_logs');
    }
};
