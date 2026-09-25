<?php

/**
 * In-process pool of reusable PHPMailer SMTP connections.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * Keeps a small number of live PHPMailer SMTP connections warm for the duration of a request,
 * so consecutive sends against the same connection can reuse an open socket instead of
 * reconnecting for every message.
 *
 * @since 1.0.0
 */
class ConnectionPool
{
    /**
     * Pooled entries keyed by {@see poolKey()}, each holding the mailer instance and its expiry.
     *
     * @since 1.0.0
     * @var array<string, array{instance: \PHPMailer\PHPMailer\PHPMailer, expires: int, created: int}>
     */
    private static array $pool = [];

    /**
     * Maximum number of connections kept in the pool at once.
     *
     * @since 1.0.0
     * @var int
     */
    private static int $maxPoolSize = 5;

    /**
     * Get a pooled connection, if one exists and has not expired.
     *
     * @since 1.0.0
     *
     * @param  string $key Pool key, see {@see poolKey()}.
     * @return \PHPMailer\PHPMailer\PHPMailer|null The pooled instance, or null when none is available.
     */
    public static function get(string $key): ?\PHPMailer\PHPMailer\PHPMailer
    {
        if (isset(self::$pool[$key])) {
            $entry = self::$pool[$key];
            if ($entry['expires'] > time()) {
                return $entry['instance'];
            }
            self::remove($key);
        }
        return null;
    }

    /**
     * Add or replace a connection in the pool.
     *
     * @since 1.0.0
     *
     * @param  string                          $key    Pool key, see {@see poolKey()}.
     * @param  \PHPMailer\PHPMailer\PHPMailer   $mailer Connected mailer instance to keep warm.
     * @param  int                              $ttl    Seconds the connection stays eligible for reuse.
     * @return void
     */
    public static function put(string $key, \PHPMailer\PHPMailer\PHPMailer $mailer, int $ttl = 30): void
    {
        if (count(self::$pool) >= self::$maxPoolSize) {
            self::evictOldest();
        }

        self::$pool[$key] = [
            'instance' => $mailer,
            'expires'  => time() + $ttl,
            'created'  => time(),
        ];
    }

    /**
     * Close and remove a pooled connection.
     *
     * @since 1.0.0
     *
     * @param  string $key Pool key, see {@see poolKey()}.
     * @return void
     */
    public static function remove(string $key): void
    {
        if (isset(self::$pool[$key])) {
            try {
                self::$pool[$key]['instance']->smtpClose();
            } catch (\Throwable) {
                // ignore
            }
            unset(self::$pool[$key]);
        }
    }

    /**
     * Close and remove every pooled connection.
     *
     * @since 1.0.0
     *
     * @return void
     */
    public static function flush(): void
    {
        foreach (self::$pool as $key => $entry) {
            try {
                $entry['instance']->smtpClose();
            } catch (\Throwable) {
                // ignore
            }
        }
        self::$pool = [];
    }

    /**
     * Build the pool key for a connection and driver pair.
     *
     * @since 1.0.0
     *
     * @param  int    $connectionId Connection id the pooled mailer belongs to.
     * @param  string $driver       Transport driver name.
     * @return string
     */
    public static function poolKey(int $connectionId, string $driver): string
    {
        return "{$driver}_{$connectionId}";
    }

    /**
     * Report current pool occupancy.
     *
     * @since 1.0.0
     *
     * @return array{total: int, active: int, max: int}
     */
    public static function stats(): array
    {
        $active = 0;
        foreach (self::$pool as $entry) {
            if ($entry['expires'] > time()) {
                $active++;
            }
        }

        return [
            'total'  => count(self::$pool),
            'active' => $active,
            'max'    => self::$maxPoolSize,
        ];
    }

    /**
     * Close and remove the longest-lived pooled connection to make room for a new one.
     *
     * @since 1.0.0
     *
     * @return void
     */
    private static function evictOldest(): void
    {
        $oldestKey  = null;
        $oldestTime = PHP_INT_MAX;

        foreach (self::$pool as $key => $entry) {
            if ($entry['created'] < $oldestTime) {
                $oldestTime = $entry['created'];
                $oldestKey  = $key;
            }
        }

        if ($oldestKey !== null) {
            self::remove($oldestKey);
        }
    }
}
