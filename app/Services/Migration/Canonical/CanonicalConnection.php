<?php
/**
 * One connection as a source adapter read it, before assessment.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Canonical;

/**
 * A source adapter builds one of these for every connection it finds. `driver` is already the
 * plugin's own driver id (`smtp`, `ses`, `google`, `outlook`, `php`, or one of the API mailers
 * the assessor may convert or refuse) and `settings` already uses the target transport's keys;
 * decoded credentials are held in memory only. `missingSecrets` names the credential keys the
 * source kept in `wp-config.php` or did not store recoverably.
 *
 * @since 1.0.0
 */
final class CanonicalConnection
{
    /**
     * @since 1.0.0
     *
     * @param string               $sourcePlugin   Source id, e.g. `fluent-smtp`.
     * @param string               $sourceKey      The connection's key inside the source (hash, id, `primary`).
     * @param string               $name           Display name proposed for the draft.
     * @param string|null          $driver         Target driver id, or null when the source mailer has no equivalent.
     * @param string               $sourceMailer   The source plugin's own mailer id, for messages.
     * @param array<string, mixed> $settings       Settings in the target transport's keys.
     * @param list<string>         $missingSecrets Credential keys the source could not hand over.
     * @param bool                 $wasDefault     Whether this was the source's default/primary connection.
     * @param string|null          $unsupportedReason Why the connection cannot be imported, when the source knows already.
     */
    public function __construct(
        public readonly string $sourcePlugin,
        public readonly string $sourceKey,
        public readonly string $name,
        public readonly ?string $driver,
        public readonly string $sourceMailer,
        public readonly array $settings,
        public readonly array $missingSecrets = [],
        public readonly bool $wasDefault = false,
        public readonly ?string $unsupportedReason = null,
    ) {}
}
