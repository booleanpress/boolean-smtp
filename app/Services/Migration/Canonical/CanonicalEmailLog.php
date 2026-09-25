<?php
/**
 * One email-log row as a source adapter read it, normalised to what the plugin's log stores.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Canonical;

/**
 * Every timestamp is already UTC (`Y-m-d H:i:s`) — the adapter converts through
 * {@see \BooleanSmtp\Services\Migration\Support\DateNormalizer} — and `status` is already one of
 * the plugin's own values (`delivered`, `failed`, `simulated`) or `pending` for a row that was
 * still in flight and is skipped.
 *
 * @since 1.0.0
 */
final class CanonicalEmailLog
{
    /**
     * @since 1.0.0
     *
     * @param string                                              $sourcePlugin   Source id.
     * @param int                                                 $sourceLogId    The row's id in the source table.
     * @param list<string>                                        $to             Recipient addresses.
     * @param string                                              $subject        Subject line.
     * @param string                                              $status         `delivered`, `failed`, `simulated` or `pending`.
     * @param string                                              $sourceStatus   The source's own status value, for the attempt record.
     * @param list<string>                                        $cc             Cc addresses.
     * @param list<string>                                        $bcc            Bcc addresses.
     * @param string|null                                         $fromEmail      Sender address.
     * @param string|null                                         $fromName       Sender name.
     * @param string|null                                         $body           Message body as the source stored it.
     * @param array<string, string>                               $headers        Header name → value.
     * @param list<array{name: string, type: string|null, size: int|null}> $attachments Attachment metadata; files are never copied.
     * @param string|null                                         $errorMessage   Delivery error text.
     * @param string|null                                         $provider       Target driver id, or the source mailer id when none matches.
     * @param string|null                                         $sourceProvider The source plugin's own mailer/type for this row, which names its connection more precisely than the driver does.
     * @param int                                                 $retries        Retry count the source recorded.
     * @param int|null                                            $deliveryTimeMs Send time in milliseconds when the source recorded it.
     * @param string|null                                         $createdAt      UTC datetime the message was recorded.
     * @param string|null                                         $initiator      What triggered the email, when the source recorded it.
     * @param array<string, mixed>                                $meta           Source-specific facts worth keeping on the attempt (an error code, a block reason).
     */
    public function __construct(
        public readonly string $sourcePlugin,
        public readonly int $sourceLogId,
        public readonly array $to,
        public readonly string $subject,
        public readonly string $status,
        public readonly string $sourceStatus,
        public readonly array $cc = [],
        public readonly array $bcc = [],
        public readonly ?string $fromEmail = null,
        public readonly ?string $fromName = null,
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly array $attachments = [],
        public readonly ?string $errorMessage = null,
        public readonly ?string $provider = null,
        public readonly ?string $sourceProvider = null,
        public readonly int $retries = 0,
        public readonly ?int $deliveryTimeMs = null,
        public readonly ?string $createdAt = null,
        public readonly ?string $initiator = null,
        public readonly array $meta = [],
    ) {}
}
