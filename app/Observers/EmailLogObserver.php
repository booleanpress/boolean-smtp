<?php
/**
 * Model observer that validates and announces email log lifecycle events.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Observers;

use BooleanSmtp\Core\Database\Orm\Observer;
use BooleanSmtp\Core\Database\Orm\Model;

/**
 * Validates recipients before an email log is created, defaults its status, protects an
 * in-progress send from deletion, and fires hooks after create and delete.
 *
 * @since 1.0.0
 */
class EmailLogObserver extends Observer
{
    /**
     * Validate and default an email log before it is inserted.
     *
     * @since 1.0.0
     *
     * @param  Model $emailLog Email log about to be created.
     * @return bool|null False to cancel creation when the recipient field has no valid address;
     *                    null to proceed.
     */
    public function creating(Model $emailLog): ?bool
    {
        $to = isset($emailLog->to) ? trim((string) $emailLog->to) : '';
        if ($to === '' || ! self::recipientFieldHasValidEmail($to)) {
            return false;
        }

        if (empty($emailLog->status)) {
            $emailLog->status = 'pending';
        }

        return null;
    }

    /**
     * Determine whether a recipient field contains at least one valid email address.
     *
     * wp_mail() allows multiple recipients as a comma-separated string or an array imploded into
     * one; {@see is_email()} only validates a single address, so the field is accepted when any
     * one of its comma-separated tokens, including the address inside a "Name <address>" form, is
     * a valid email.
     *
     * @since 1.0.0
     *
     * @param  string $to Raw recipient field value.
     * @return bool True when at least one token is a valid email address.
     */
    private static function recipientFieldHasValidEmail(string $to): bool
    {
        if (\is_email($to)) {
            return true;
        }

        foreach (array_map('trim', explode(',', $to)) as $part) {
            if ($part === '') {
                continue;
            }

            if (preg_match('/<\s*([^>]+)\s*>/', $part, $m)) {
                $addr = trim($m[1]);
                if (\is_email($addr)) {
                    return true;
                }

                continue;
            }

            if (\is_email($part)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Announce that an email log was created.
     *
     * @since 1.0.0
     *
     * @param  Model $emailLog Email log that was created.
     */
    public function created(Model $emailLog): void
    {
        /**
         * Fires after an email log entry has been created.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload Email log summary: id, to, subject and status.
         */
        \do_action('boolean_smtp_email_logged', [
            'id'      => $emailLog->id,
            'to'      => $emailLog->to,
            'subject' => $emailLog->subject,
            'status'  => $emailLog->status,
        ]);
    }

    /**
     * Protect an in-progress send from deletion.
     *
     * @since 1.0.0
     *
     * @param  Model $emailLog Email log about to be deleted.
     * @return bool|null False to cancel deletion when the log's status is "sending"; null to
     *                    proceed.
     */
    public function deleting(Model $emailLog): ?bool
    {
        if ($emailLog->status === 'sending') {
            return false;
        }

        return null;
    }

    /**
     * Announce that an email log was deleted.
     *
     * @since 1.0.0
     *
     * @param  Model $emailLog Email log that was deleted.
     */
    public function deleted(Model $emailLog): void
    {
        /**
         * Fires after an email log entry has been deleted.
         *
         * @since 1.0.0
         *
         * @param array<string, mixed> $payload Deleted email log identifier: id.
         */
        \do_action('boolean_smtp_email_log_deleted', [
            'id' => $emailLog->id,
        ]);
    }
}
