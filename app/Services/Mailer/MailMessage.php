<?php

/**
 * Normalized representation of an outgoing message used by the mail pipeline.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * Normalized wp_mail payload for BooleanSMTP lifecycle hooks (not a full MIME object).
 *
 * @since 1.0.0
 *
 * @phpstan-type MetaArray array<string, mixed>
 */
final class MailMessage
{
    /**
     * Create a message.
     *
     * @since 1.0.0
     *
     * @param array<int, string> $to          Recipient email addresses.
     * @param string             $subject     Message subject.
     * @param string             $message     Message body, HTML or plain text.
     * @param array|string       $headers     Header lines, wp_mail style (string with newlines or array of lines).
     * @param array|string       $attachments Absolute file paths, wp_mail style (string or array).
     * @param MetaArray          $meta        Additional context carried alongside the message (for example, embeds).
     */
    public function __construct(
        public array $to,
        public string $subject,
        public string $message,
        public mixed $headers = '',
        public mixed $attachments = [],
        public array $meta = [],
    ) {
    }

    /**
     * Build a message from the arguments passed through the wp_mail filter.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $atts After {@see apply_filters('wp_mail', ...)}
     * @return self
     */
    public static function fromWpMailAtts(array $atts): self
    {
        $to = $atts['to'] ?? '';
        if (! \is_array($to)) {
            $to = array_filter(array_map('trim', explode(',', (string) $to)));
        }

        return new self(
            $to,
            (string) ($atts['subject'] ?? ''),
            (string) ($atts['message'] ?? ''),
            $atts['headers'] ?? '',
            $atts['attachments'] ?? [],
            isset($atts['embeds']) && \is_array($atts['embeds']) ? ['embeds' => $atts['embeds']] : []
        );
    }

    /**
     * Convert the message back into the array shape wp_mail() expects.
     *
     * @since 1.0.0
     *
     * @return array<string, mixed>
     */
    public function toWpMailAttsArray(): array
    {
        $atts = [
            'to'          => $this->to,
            'subject'     => $this->subject,
            'message'     => $this->message,
            'headers'     => $this->headers,
            'attachments' => $this->attachments,
        ];
        if (isset($this->meta['embeds']) && \is_array($this->meta['embeds'])) {
            $atts['embeds'] = $this->meta['embeds'];
        }

        return $atts;
    }
}
