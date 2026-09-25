<?php

/**
 * Builds a PHPMailer instance from raw wp_mail() arguments.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

/**
 * Builds a PHPMailer instance from wp_mail()-style $atts (after wp_mail filter).
 * Mirrors WordPress core wp_mail setup up to phpmailer_init (see wp-includes/pluggable.php).
 *
 * @since 1.0.0
 */
final class WpMailMimeBuilder
{
    /**
     * Build and populate a PHPMailer instance the same way WordPress core's wp_mail() does,
     * stopping just short of the point where core would fire phpmailer_init and send.
     *
     * @since 1.0.0
     *
     * @param  array{to?: mixed, subject?: string, message?: string, headers?: mixed, attachments?: mixed} $atts
     *         wp_mail-style arguments, after the wp_mail filter has run.
     * @return \PHPMailer\PHPMailer\PHPMailer Populated mailer instance, not yet sent.
     *
     * @throws \RuntimeException When the sender address cannot be applied to the mailer.
     */
    public static function createPhpmailerFromAtts(array $atts): \PHPMailer\PHPMailer\PHPMailer
    {
        \BooleanSmtp\Support\WordPressMailerLoader::ensureLoaded();

        $to          = $atts['to'] ?? '';
        $subject     = $atts['subject'] ?? '';
        $message     = $atts['message'] ?? '';
        $headers     = $atts['headers'] ?? '';
        $attachments = $atts['attachments'] ?? [];

        if (! \is_array($to)) {
            $to = explode(',', (string) $to);
        }

        if (! \is_array($attachments)) {
            $attachments = explode("\n", str_replace("\r\n", "\n", (string) $attachments));
        }

        $cc       = [];
        $bcc      = [];
        $reply_to = [];

        if (empty($headers)) {
            $headers = [];
        } else {
            if (! \is_array($headers)) {
                $tempheaders = explode("\n", str_replace("\r\n", "\n", (string) $headers));
            } else {
                $tempheaders = $headers;
            }
            $headers = [];

            if (! empty($tempheaders)) {
                foreach ((array) $tempheaders as $header) {
                    if (! str_contains((string) $header, ':')) {
                        if (false !== stripos((string) $header, 'boundary=')) {
                            $parts = preg_split('/boundary=/i', trim((string) $header));
                            if (isset($parts[1])) {
                                $boundary = trim(str_replace(["'", '"'], '', $parts[1]));
                            }
                        }
                        continue;
                    }
                    [$name, $content] = explode(':', trim((string) $header), 2);
                    $name    = trim($name);
                    $content = trim($content);

                    switch (strtolower($name)) {
                        case 'from':
                            $bracket_pos = strpos($content, '<');
                            if (false !== $bracket_pos) {
                                if ($bracket_pos > 0) {
                                    $from_name = substr($content, 0, $bracket_pos);
                                    $from_name = str_replace('"', '', $from_name);
                                    $from_name = trim($from_name);
                                }
                                $from_email = substr($content, $bracket_pos + 1);
                                $from_email = str_replace('>', '', $from_email);
                                $from_email = trim($from_email);
                            } elseif ('' !== trim($content)) {
                                $from_email = trim($content);
                            }
                            break;
                        case 'content-type':
                            if (str_contains($content, ';')) {
                                [$type, $charset_content] = explode(';', $content, 2);
                                $content_type = trim($type);
                                if (false !== stripos($charset_content, 'charset=')) {
                                    $charset = trim(str_replace(['charset=', '"'], '', $charset_content));
                                } elseif (false !== stripos($charset_content, 'boundary=')) {
                                    $boundary = trim(str_replace(['BOUNDARY=', 'boundary=', '"'], '', $charset_content));
                                    $charset  = '';
                                }
                            } elseif ('' !== trim($content)) {
                                $content_type = trim($content);
                            }
                            break;
                        case 'cc':
                            $cc = array_merge((array) $cc, explode(',', $content));
                            break;
                        case 'bcc':
                            $bcc = array_merge((array) $bcc, explode(',', $content));
                            break;
                        case 'reply-to':
                            $reply_to = array_merge((array) $reply_to, explode(',', $content));
                            break;
                        default:
                            $headers[trim($name)] = trim($content);
                            break;
                    }
                }
            }
        }

        $phpmailer = \BooleanSmtp\Support\WordPressMailerLoader::createInstance(true);

        $phpmailer->clearAllRecipients();
        $phpmailer->clearAttachments();
        $phpmailer->clearCustomHeaders();
        $phpmailer->clearReplyTos();
        $phpmailer->Body    = '';
        $phpmailer->AltBody = '';

        if (! isset($from_name)) {
            $from_name = 'WordPress';
        }

        if (! isset($from_email)) {
            $sitename = \function_exists('wp_parse_url') && \function_exists('network_home_url')
                ? \wp_parse_url(\network_home_url(), PHP_URL_HOST)
                : null;
            $from_email = 'wordpress@';
            if (null !== $sitename && \is_string($sitename)) {
                if (str_starts_with($sitename, 'www.')) {
                    $sitename = substr($sitename, 4);
                }
                $from_email .= $sitename;
            }
        }

        /**
         * Filters the email address to send from.
         *
         * @since 1.0.0
         *
         * @param string $from_email Email address to send from.
         */
        $from_email = \apply_filters('wp_mail_from', $from_email); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.

        /**
         * Filters the name to associate with the "from" email address.
         *
         * @since 1.0.0
         *
         * @param string $from_name Name associated with the "from" email address.
         */
        $from_name  = \apply_filters('wp_mail_from_name', $from_name); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.

        try {
            $phpmailer->setFrom($from_email, $from_name, false);
        } catch (\PHPMailer\PHPMailer\Exception $e) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- the message is returned as JSON text or logged, never printed as HTML.
            throw new \RuntimeException($e->getMessage(), (int) $e->getCode(), $e);
        }

        $phpmailer->Subject = $subject;
        $phpmailer->Body    = $message;

        $address_headers = compact('to', 'cc', 'bcc', 'reply_to');
        foreach ($address_headers as $address_header => $addresses) {
            if (empty($addresses)) {
                continue;
            }
            foreach ((array) $addresses as $address) {
                try {
                    $recipient_name = '';
                    if (preg_match('/(.*)<(.+)>/', (string) $address, $matches) && count($matches) === 3) {
                        $recipient_name = $matches[1];
                        $address        = $matches[2];
                    }
                    switch ($address_header) {
                        case 'to':
                            $phpmailer->addAddress(trim((string) $address), $recipient_name);
                            break;
                        case 'cc':
                            $phpmailer->addCc(trim((string) $address), $recipient_name);
                            break;
                        case 'bcc':
                            $phpmailer->addBcc(trim((string) $address), $recipient_name);
                            break;
                        case 'reply_to':
                            $phpmailer->addReplyTo(trim((string) $address), $recipient_name);
                            break;
                    }
                } catch (\PHPMailer\PHPMailer\Exception) {
                    continue;
                }
            }
        }

        $phpmailer->isMail();

        if (! isset($content_type)) {
            $content_type = 'text/plain';
        }
        /**
         * Filters the wp_mail() content type.
         *
         * @since 1.0.0
         *
         * @param string $content_type Default wp_mail() content type.
         */
        $content_type = \apply_filters('wp_mail_content_type', $content_type); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.
        $phpmailer->ContentType = $content_type;
        if ('text/html' === $phpmailer->ContentType) {
            $phpmailer->isHTML(true);
        }

        if (! isset($charset)) {
            $charset = \function_exists('get_bloginfo') ? \get_bloginfo('charset') : 'UTF-8';
        }
        /**
         * Filters the default wp_mail() charset.
         *
         * @since 1.0.0
         *
         * @param string $charset Default email charset.
         */
        $phpmailer->CharSet = \apply_filters('wp_mail_charset', $charset); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's own mail hook, fired on purpose so API sends keep wp_mail() behaviour for other plugins.

        if (! empty($headers)) {
            foreach ((array) $headers as $name => $content) {
                if (! \in_array($name, ['MIME-Version', 'X-Mailer'], true)) {
                    try {
                        $phpmailer->addCustomHeader(sprintf('%1$s: %2$s', $name, $content));
                    } catch (\PHPMailer\PHPMailer\Exception) {
                        continue;
                    }
                }
            }
            if (isset($content_type) && false !== stripos($content_type, 'multipart') && ! empty($boundary)) {
                $phpmailer->addCustomHeader(sprintf('Content-Type: %s; boundary="%s"', $content_type, $boundary));
            }
        }

        if (! empty($attachments)) {
            foreach ($attachments as $filename => $attachment) {
                $filename = \is_string($filename) ? $filename : '';
                try {
                    $phpmailer->addAttachment((string) $attachment, $filename);
                } catch (\PHPMailer\PHPMailer\Exception) {
                    continue;
                }
            }
        }

        return $phpmailer;
    }
}
