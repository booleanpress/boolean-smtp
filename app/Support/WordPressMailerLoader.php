<?php

/**
 * Loads and instantiates PHPMailer from WordPress core rather than a bundled copy.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Support;

/**
 * PHPMailer must come only from WordPress core (wp-includes/PHPMailer).
 *
 * BooleanSMTP does not ship or Composer-require PHPMailer. All code paths that need
 * PHPMailer should call {@see ensureLoaded()} or {@see createInstance()} so the
 * bundled WordPress copies are loaded before use.
 *
 * @since 1.0.0
 */
final class WordPressMailerLoader
{
    /**
     * Load WordPress core's bundled PHPMailer classes if they are not already loaded.
     *
     * Does nothing outside of WordPress (when `ABSPATH`/`WPINC` are undefined) or when PHPMailer
     * is already loaded.
     *
     * @since 1.0.0
     */
    public static function ensureLoaded(): void
    {
        if (class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
            return;
        }

        if (!\defined('ABSPATH') || !\defined('WPINC')) {
            return;
        }

        $base = \ABSPATH . \WPINC . '/PHPMailer/';

        require_once $base . 'PHPMailer.php';
        require_once $base . 'SMTP.php';
        require_once $base . 'Exception.php';
    }

    /**
     * Instantiate PHPMailer after loading WordPress core's bundled classes.
     *
     * @since 1.0.0
     *
     * @param  bool $exceptions Whether PHPMailer should throw exceptions on error.
     * @return \PHPMailer\PHPMailer\PHPMailer
     *
     * @throws \RuntimeException When running outside WordPress or the core PHPMailer files are missing.
     */
    public static function createInstance(bool $exceptions = true): \PHPMailer\PHPMailer\PHPMailer
    {
        self::ensureLoaded();

        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class, false)) {
            throw new \RuntimeException(
                'PHPMailer is not available. BooleanSMTP uses only WordPress core’s PHPMailer (wp-includes/PHPMailer).'
            );
        }

        return new \PHPMailer\PHPMailer\PHPMailer($exceptions);
    }
}
