<?php
/**
 * The verdict on one source connection: what it maps to, what is missing, what is created.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Assessment;

use BooleanSmtp\Contracts\EncryptorContract;

/**
 * Produced by {@see ConnectionAssessor} from a canonical connection and returned by every dry
 * run and import. `settings` holds the decoded credentials for the destination only;
 * {@see toArray()} masks them the way the connection API does (asterisks and the last four
 * characters), and reports a missing one as an empty string.
 *
 * @since 1.0.0
 */
final class ConnectionAssessment
{
    /** @since 1.0.0 */
    public const READY = 'ready';
    /** @since 1.0.0 */
    public const NEEDS_PASSWORD = 'needs_password';
    /** @since 1.0.0 */
    public const NEEDS_AUTHORIZATION = 'needs_authorization';
    /** @since 1.0.0 */
    public const NEEDS_REVIEW = 'needs_review';
    /** @since 1.0.0 */
    public const UNSUPPORTED = 'unsupported';

    /**
     * Id of the draft created or updated for this connection, set by the destination.
     *
     * @since 1.0.0
     * @var int|null
     */
    public ?int $connectionId = null;

    /**
     * The site connection that already uses this connection's sender, when the sender rule does
     * not allow both: `existing_id`, `existing_name`, `sender`, `replace_allowed`, `message`.
     *
     * @since 1.0.0
     * @var array{existing_id: int, existing_name: string, sender: string, replace_allowed: bool, message: string}|null
     */
    public ?array $senderConflict = null;

    /**
     * What the run did with the connection: `imported`, `replaced`, `kept` (the site's connection
     * with the same sender was kept and this one not imported) or `skipped` (another connection
     * of the same source already has this sender). Null for a dry run and for `unsupported`.
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $outcome = null;

    /**
     * Why the connection is skipped, when another connection of the same source has its sender.
     *
     * @since 1.0.0
     * @var string|null
     */
    public ?string $skippedReason = null;

    /**
     * The connection test run straight after a replacement: `healthy` and `error`.
     *
     * @since 1.0.0
     * @var array{healthy: bool, error: string|null}|null
     */
    public ?array $test = null;

    /**
     * @since 1.0.0
     *
     * @param string                          $source       Source id.
     * @param string                          $sourceKey    The connection's key inside the source.
     * @param string                          $name         Draft name.
     * @param string|null                     $driver       Target driver id; null for `unsupported`.
     * @param string                          $sourceMailer The source plugin's mailer id.
     * @param array<string, mixed>            $settings     Settings in the target transport's keys, credentials included.
     * @param string                          $status       One of the class constants.
     * @param list<string>                    $missing      Credential keys the user has to enter again.
     * @param list<array{key: string, message: string}> $issues Validation findings on non-credential keys.
     * @param string|null                     $conversion   `sendgrid_relay` or `postmark_relay` when converted.
     * @param bool                            $wasDefault   Whether this was the source's default connection.
     * @param string|null                     $reason       Why the connection is `unsupported`.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $sourceKey,
        public readonly string $name,
        public readonly ?string $driver,
        public readonly string $sourceMailer,
        public readonly array $settings,
        public readonly string $status,
        public readonly array $missing = [],
        public readonly array $issues = [],
        public readonly ?string $conversion = null,
        public readonly bool $wasDefault = false,
        public readonly ?string $reason = null,
    ) {}

    /**
     * Whether the destination creates a draft for it.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function importable(): bool
    {
        return $this->status !== self::UNSUPPORTED && $this->driver !== null;
    }

    /**
     * Array form for the REST and CLI responses: every credential masked as the connection API
     * masks it (asterisks plus the last four characters, values of four characters or fewer
     * left as they are), an absent one an empty string.
     *
     * @since 1.0.0
     *
     * @param  EncryptorContract $encryptor Decides which keys are credentials.
     * @return array<string, mixed>
     */
    public function toArray(EncryptorContract $encryptor): array
    {
        $settings = [];
        foreach ($this->settings as $key => $value) {
            if ($encryptor->isSensitiveKey((string) $key) && \is_string($value) && strlen($value) > 4) {
                $settings[$key] = str_repeat('*', strlen($value) - 4) . substr($value, -4);
            } else {
                $settings[$key] = $value;
            }
        }

        return [
            'source'        => $this->source,
            'source_key'    => $this->sourceKey,
            'name'          => $this->name,
            'driver'        => $this->driver,
            'source_mailer' => $this->sourceMailer,
            'status'        => $this->status,
            'missing'       => $this->missing,
            'issues'        => $this->issues,
            'conversion'    => $this->conversion,
            'was_default'   => $this->wasDefault,
            'reason'        => $this->reason,
            'connection_id' => $this->connectionId,
            'settings'      => $settings,
            'sender_conflict' => $this->senderConflict,
            'outcome'         => $this->outcome,
            'skipped_reason'  => $this->skippedReason,
            'test'            => $this->test,
        ];
    }
}
