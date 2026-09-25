<?php
/**
 * Eloquent-style model for a single logged email send.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Models;

use BooleanSmtp\Core\Database\Orm\Model;
use function BooleanSmtp\Core\app;

/**
 * Represents a row in the email logs table: the recipients, content, delivery status and
 * diagnostics of one send attempt.
 *
 * @since 1.0.0
 */
class EmailLog extends Model
{
    /**
     * Database table backing this model.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $table = 'boolean_smtp_email_logs';

    /**
     * Attributes that may be mass-assigned.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    protected array $fillable = [
        'to',
        'cc',
        'bcc',
        'from_email',
        'from_name',
        'subject',
        'body',
        'headers',
        'status',
        'provider',
        'connection_id',
        'error_message',
        'message_id',
        'retries',
        'delivery_time_ms',
        'attempts',
        'attachments',
        'source',
        'backtrace',
        'last_attempted_at',
        'next_attempt_at',
        'reserved_by',
        'reserved_at',
    ];

    /**
     * Attribute type casts applied when reading and writing this model.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    protected array $casts = [
        'headers'          => 'array',
        'connection_id'    => 'integer',
        'retries'          => 'integer',
        'delivery_time_ms' => 'integer',
        'attempts'         => 'array',
        'attachments'      => 'array',
        'backtrace'        => 'array',
    ];

    /**
     * Attributes excluded when this model is serialized.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    protected array $hidden = [
        'body',
        'attachments',
    ];

    /**
     * Connection used to send this email, if any.
     *
     * @since 1.0.0
     *
     * @return \BooleanSmtp\Core\Database\Orm\Relations\BelongsTo
     */
    public function connection()
    {
        return $this->belongsTo(Connection::class, 'connection_id');
    }

    /**
     * Load this email's SMTP debug session.
     *
     * Sessions are files, one per email log entry, read through {@see \BooleanSmtp\Repositories\DebugLogRepository}
     * rather than a relationship.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>|null The session (`lines`, `level`, `line_count`, …), or null when
     *                                   this model has no ID yet or no session was captured.
     */
    public function debugLogs(): ?array
    {
        if (!$this->id) {
            return null;
        }
        return app(\BooleanSmtp\Repositories\DebugLogRepository::class)->getForEmail((int) $this->id);
    }

    /**
     * Scope a query to email logs with a "failed" status.
     *
     * @since 1.0.0
     *
     * @param  mixed $query Query builder instance.
     * @return mixed The scoped query builder.
     */
    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    /**
     * Scope a query to email logs with a "delivered" status.
     *
     * @since 1.0.0
     *
     * @param  mixed $query Query builder instance.
     * @return mixed The scoped query builder.
     */
    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    /**
     * Scope a query to email logs created within the last N days.
     *
     * @since 1.0.0
     *
     * @param  mixed $query Query builder instance.
     * @param  int   $days  Number of days to look back.
     * @return mixed The scoped query builder.
     */
    public function scopeRecent($query, int $days = 7)
    {
        $date = gmdate('Y-m-d H:i:s', time() - ($days * 86400));
        return $query->where('created_at', '>=', $date);
    }
}
