<?php
/**
 * Eloquent-style model for a configured mail-sending connection.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Models;

use BooleanSmtp\Core\Database\Orm\Model;

/**
 * Represents a row in the connections table: a mail provider (SMTP, API, etc.) with its settings,
 * activation state and health status.
 *
 * @since 1.0.0
 */
class Connection extends Model
{
    /**
     * Database table backing this model.
     *
     * @since 1.0.0
     * @var string
     */
    protected string $table = 'boolean_smtp_connections';

    /**
     * Attributes that may be mass-assigned.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    protected array $fillable = [
        'name',
        'driver',
        'settings',
        'is_active',
        'priority',
        'health_status',
        'last_used_at',
        'last_error',
        'oauth_refresh_history',
    ];

    /**
     * Attribute type casts applied when reading and writing this model.
     *
     * @since 1.0.0
     * @var array<string, string>
     */
    protected array $casts = [
        'settings'              => 'array',
        'is_active'             => 'boolean',
        'priority'              => 'integer',
        'oauth_refresh_history' => 'array',
    ];

    /**
     * Attributes excluded when this model is serialized.
     *
     * @since 1.0.0
     * @var array<int, string>
     */
    protected array $hidden = [
        'oauth_refresh_history',
    ];

    /**
     * Email logs recorded against this connection.
     *
     * @since 1.0.0
     *
     * @return \BooleanSmtp\Core\Database\Orm\Relations\HasMany
     */
    public function emailLogs()
    {
        return $this->hasMany(EmailLog::class, 'connection_id');
    }
}
