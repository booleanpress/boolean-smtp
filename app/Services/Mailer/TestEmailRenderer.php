<?php
/**
 * Renders safe, transport-receipt test-email bodies from configured pre-send facts.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Mailer;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Models\Connection;
use BooleanSmtp\Repositories\ConnectionRepository;
use BooleanSmtp\Support\Settings;

/**
 * Builds equivalent HTML and plain-text test-email receipts without resolving the live send route.
 *
 * The renderer reads only the configured connection/default candidate and sender settings. It does
 * not ask {@see MailerManager} to resolve a message, so a one-shot forced connection remains
 * untouched until the actual `wp_mail()` send begins.
 *
 * @since 1.0.0
 */
final class TestEmailRenderer {
    /**
     * Create the test-email renderer.
     *
     * @since 1.0.0
     *
     * @param ConnectionRepository $connections Reads explicit connections and the configured default candidate.
     * @param Settings             $settings    Reads global sender and message-format settings.
     * @param TranslatorContract   $translator  Translates recipient-facing static copy.
     */
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly Settings $settings,
        private readonly TranslatorContract $translator
    ) {
    }

    /**
     * Render both supported body formats from one configured test profile.
     *
     * The caller picks the format it sends. The receipt describes the configuration only, never the
     * recipient or the subject, so neither is taken here. `requestedConnectionId` is resolved through
     * the repository rather than `MailerManager`: this describes the configuration selected for the
     * test and must not consume MailerManager's one-shot forced-connection state before the send.
     *
     * @since 1.0.0
     *
     * @param int|null $requestedConnectionId Explicit connection selected for this test, or null for automatic routing.
     * @return array{html: string, plain: string} Equivalent HTML and plain-text test-message bodies.
     */
    public function render(?int $requestedConnectionId = null): array {
        $facts = $this->facts($requestedConnectionId);

        return [
            'html'  => $this->html($facts),
            'plain' => $this->plain($facts),
        ];
    }

    /**
     * Collect the non-sensitive configured facts shown in a test receipt.
     *
     * @since 1.0.0
     *
     * @param int|null  $requestedConnectionId Explicit connection selected for this test, or null for automatic routing.
     * @return array<string, string> Safe receipt fields, in their display order.
     */
    private function facts(?int $requestedConnectionId): array {
        $connection = $this->configuredConnection($requestedConnectionId);
        $settings   = $connection !== null && \is_array($connection->settings ?? null)
            ? $connection->settings
            : [];

        $explicit = $requestedConnectionId !== null && $requestedConnectionId > 0;
        return [
            $this->t('Requested route') => $explicit
                ? $this->t('Explicit connection')
                : $this->t('Automatic/default route'),
            $this->t('Connection') => $this->connectionName($connection, $requestedConnectionId),
            $this->t('Mailer driver') => $this->driverLabel($connection),
            $this->t('Delivery mode') => $this->deliveryModeLabel($connection, $settings),
            $this->t('Configured sender') => $this->configuredSender($settings),
            $this->t('Site URL') => $this->siteUrl(),
            $this->t('UTC timestamp') => gmdate('Y-m-d H:i:s') . ' UTC',
            $this->t('Plugin version') => 'v' . $this->oneLine((string) BOOLEAN_SMTP_VERSION),
        ];
    }

    /**
     * Find the configured connection that may safely be described before the send.
     *
     * An explicit test can intentionally target an inactive onboarding draft, so it is read
     * directly. Automatic routing displays only the configured default/primary candidate; routing
     * rules and message-specific sender matching still run later during the real send.
     *
     * @since 1.0.0
     *
     * @param int|null $requestedConnectionId Explicit connection selected for this test, or null.
     * @return Connection|null Configured connection to display, or null when none is available.
     */
    private function configuredConnection(?int $requestedConnectionId): ?Connection {
        if ($requestedConnectionId !== null && $requestedConnectionId > 0) {
            return $this->connections->find($requestedConnectionId);
        }

        $rawDefault = $this->settings->get('default_connection_id');
        $defaultId  = \is_numeric($rawDefault) && (int) $rawDefault > 0
            ? (int) $rawDefault
            : null;

        return $this->connections->getDefaultOrPrimary($defaultId);
    }

    /**
     * Format the configured connection name without revealing a missing row as a valid route.
     *
     * @since 1.0.0
     *
     * @param Connection|null $connection            Configured connection, when found.
     * @param int|null        $requestedConnectionId Explicit requested identifier, when supplied.
     * @return string Human-readable configured connection label.
     */
    private function connectionName(?Connection $connection, ?int $requestedConnectionId): string {
        if ($connection !== null) {
            $name = $this->oneLine((string) ($connection->name ?? ''));

            return $name !== '' ? $name : $this->t('Unnamed connection');
        }

        if ($requestedConnectionId !== null && $requestedConnectionId > 0) {
            return $this->t('Requested connection #{{id}}', ['id' => (string) $requestedConnectionId]);
        }

        return $this->t('No configured connection');
    }

    /**
     * Render a safe human-readable mailer driver label.
     *
     * @since 1.0.0
     *
     * @param Connection|null $connection Configured connection, when found.
     * @return string Driver label or a clear configuration fallback.
     */
    private function driverLabel(?Connection $connection): string {
        if ($connection === null) {
            return $this->t('Not configured');
        }

        $driver = strtolower($this->oneLine((string) ($connection->driver ?? '')));

        return match ($driver) {
            'php'        => $this->t('WordPress mail'),
            'smtp'       => $this->t('SMTP'),
            'google'     => $this->t('Google'),
            'outlook'    => $this->t('Microsoft 365'),
            'ses'        => $this->t('Amazon SES'),
            'postmark'   => $this->t('Postmark'),
            'mailgun'    => $this->t('Mailgun'),
            'sendgrid'   => $this->t('SendGrid'),
            'brevo'      => $this->t('Brevo'),
            'simulation' => $this->t('Simulation'),
            ''           => $this->t('Not configured'),
            default      => $this->oneLine($driver),
        };
    }

    /**
     * Render a safe human-readable configured delivery-mode label.
     *
     * @since 1.0.0
     *
     * @param Connection|null       $connection Configured connection, when found.
     * @param array<string, mixed> $settings   Non-sensitive connection settings used only for delivery mode.
     * @return string Configured delivery-mode label.
     */
    private function deliveryModeLabel(?Connection $connection, array $settings): string {
        if ($connection === null) {
            return $this->t('Not configured');
        }

        $fallback = (string) ($connection->driver === 'php' ? 'wp_mail' : 'api');
        $mode     = strtolower($this->oneLine((string) ($settings['delivery_mode'] ?? $fallback)));

        return match ($mode) {
            'wp_mail'        => $this->t('WordPress default mail'),
            'smtp'           => $this->t('SMTP'),
            'api'            => $this->t('API'),
            'one_click'      => $this->t('One-click connection'),
            'app_permission' => $this->t('App permission'),
            ''               => $this->t('Not configured'),
            default          => $this->oneLine($mode),
        };
    }

    /**
     * Format the sender identity configured for the displayed profile.
     *
     * A site-wide forced sender wins exactly as it does in MailerManager. Otherwise this is a
     * configured value only, not a claim about a final sender selected by another mail plugin or
     * a message-specific routing rule.
     *
     * @since 1.0.0
     *
     * @param array<string, mixed> $connectionSettings Non-sensitive settings from the displayed connection.
     * @return string Configured sender name and email, or a configuration fallback.
     */
    private function configuredSender(array $connectionSettings): string {
        $globalEmail = $this->oneLine((string) $this->settings->get('from_email', ''));
        $globalName  = $this->oneLine((string) $this->settings->get('from_name', ''));
        $forceGlobal = $this->isTruthy($this->settings->get('force_from', false));

        $connectionEmail = $this->oneLine((string) ($connectionSettings['from_email'] ?? ''));
        $connectionName  = $this->oneLine((string) ($connectionSettings['from_name'] ?? ''));

        if ($forceGlobal && $globalEmail !== '') {
            return $this->mailbox($globalName, $globalEmail);
        }

        if ($connectionEmail !== '') {
            $name = $forceGlobal && $globalName !== '' ? $globalName : $connectionName;

            return $this->mailbox($name, $connectionEmail);
        }

        if ($globalEmail !== '') {
            return $this->mailbox($globalName, $globalEmail);
        }

        return $this->t('Not configured');
    }

    /**
     * Render the compatibility-first HTML receipt.
     *
     * The composition follows a transactional-email hierarchy: pale canvas, a compact branded
     * hero, a white content surface, detail tables, and a quiet exterior footer. The hero and
     * content surface are separate tables so their restrained outer corners never expose the navy
     * hero below the white content. It uses an Outlook ghost table, HTML attributes for the layout
     * baseline, and restrained inline CSS. There are deliberately no media queries, word-break
     * rules, remote assets, SVG, gradients, scripts, flexbox, or grid layout.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $facts Safe receipt fields in display order.
     * @return string HTML email body.
     */
    private function html(array $facts): string {
        $tables = '';
        foreach ($this->factGroups($facts) as $index => $group) {
            $rows = '';
            $lastLabel = array_key_last($group['facts']);
            foreach ($group['facts'] as $label => $value) {
                $divider = $label !== $lastLabel ? 'border-bottom:1px solid #e2e8f0;' : '';
                $rows .= '<tr>'
                    . '<td width="42%" valign="top" style="padding:12px 16px;color:#475569;font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:20px;mso-line-height-rule:exactly;' . $divider . '"><strong>' . $this->escape($label) . '</strong></td>'
                    . '<td align="right" valign="top" style="padding:12px 16px;color:#0f172a;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;mso-line-height-rule:exactly;' . $divider . '">' . $this->escapeDisplayValue($value) . '</td>'
                    . '</tr>';
            }

            $tables .= '<tr><td style="padding:' . ($index === 0 ? '0' : '30px') . ' 0 14px;"><h2 style="margin:0;color:#111827;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:28px;mso-line-height-rule:exactly;">' . $this->escape($group['heading']) . '</h2></td></tr>'
                . '<tr><td>'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#e2e8f0">'
                . '<tr><td style="padding:1px;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#ffffff" style="border-collapse:collapse;">'
                . $rows
                . '</table></td></tr></table>'
                . '</td></tr>';
        }

        $title       = $this->escape($this->t('BooleanSMTP test email'));
        $adminLabel  = $this->escape($this->t('Open BooleanSMTP'));
        $adminUrl    = $this->escape($this->adminUrl());
        $heroTitle   = $this->escape($this->t('Test email received'));
        $heroText    = $this->escape($this->t('The delivery profile used for this check is recorded below.'));
        $thankYou    = $this->escape($this->t('Thank you for choosing BooleanSMTP. The details below provide a concise record of this test configuration.'));
        $signOff     = $this->escape($this->t('Thanks,'));
        $signOffName = $this->escape($this->t('BooleanPress'));
        $notice      = $this->escape($this->t('This confirms only that this test reached this mailbox; it does not guarantee future inbox placement or overall deliverability.'));
        $footerBrand = $this->escape($this->t('BooleanSMTP · WordPress email delivery'));
        $footerYear  = $this->escape($this->t('© {{year}} BooleanSMTP', ['year' => gmdate('Y')]));

        return '<!doctype html>'
            . '<html lang="en" dir="ltr" xmlns:o="urn:schemas-microsoft-com:office:office"><head><meta charset="utf-8"><meta name="x-apple-disable-message-reformatting"><meta name="viewport" content="width=device-width,initial-scale=1.0"><meta name="format-detection" content="telephone=no,date=no,address=no,email=no,url=no"><title>' . $title . '</title>'
            . '<!--[if mso]><xml><o:OfficeDocumentSettings><o:PixelsPerInch>96</o:PixelsPerInch></o:OfficeDocumentSettings></xml><![endif]--></head>'
            . '<body lang="en" dir="ltr" bgcolor="#f7f8fa">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" bgcolor="#f7f8fa">'
            . '<tr><td align="center" style="padding:24px 16px 0;">'
            . '<!--[if mso]><table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" align="center"><tr><td><![endif]-->'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#111827" style="max-width:640px;border-radius:16px 16px 0 0;">'
            . '<tr><td style="padding:28px 40px 0;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td valign="top" style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:20px;line-height:26px;mso-line-height-rule:exactly;"><strong>' . $this->escape($this->t('BooleanSMTP')) . '</strong></td>'
            . '<td align="right" valign="top" style="color:#9ca3af;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;mso-line-height-rule:exactly;"><strong>' . $this->escape($this->t('Test email')) . '</strong></td>'
            . '</tr></table></td></tr>'
            . '<tr><td style="padding:30px 40px 0;"><h1 style="margin:0;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:34px;line-height:42px;mso-line-height-rule:exactly;">' . $heroTitle . '</h1></td></tr>'
            . '<tr><td style="padding:10px 40px 28px;color:#d1d5db;font-family:Arial,Helvetica,sans-serif;font-size:18px;line-height:26px;mso-line-height-rule:exactly;">' . $heroText . '</td></tr>'
            . '</table>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" align="center" bgcolor="#ffffff" style="max-width:640px;border-radius:0 0 16px 16px;">'
            . '<tr><td style="padding:32px 40px 42px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0">'
            . '<tr><td style="padding:0 0 16px;color:#475569;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:24px;mso-line-height-rule:exactly;">' . $thankYou . '</td></tr>'
            . '<tr><td style="padding:0 0 28px;color:#475569;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;">' . $signOff . '<br><strong style="color:#111827;">' . $signOffName . '</strong></td></tr>'
            . $tables
            . '<tr><td style="padding:20px 0 0;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:22px;mso-line-height-rule:exactly;">' . $notice . '</td></tr>'
            . '<tr><td align="center" style="padding:30px 0 0;"><table role="presentation" align="center" cellspacing="0" cellpadding="0" border="0"><tr><td align="center" bgcolor="#111827" style="padding:13px 22px;border-radius:8px;"><a href="' . $adminUrl . '" style="color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;mso-line-height-rule:exactly;text-decoration:none;"><strong>' . $adminLabel . '</strong></a></td></tr></table></td></tr>'
            . '</table></td></tr></table>'
            . '<!--[if mso]></td></tr></table><![endif]-->'
            . '</td></tr>'
            . '<tr><td align="center" style="padding:24px 24px 40px;color:#64748b;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;mso-line-height-rule:exactly;">'
            . $footerBrand . '<br>' . $footerYear
            . '</td></tr></table></body></html>';
    }

    /**
     * Render the plain-text receipt from the same facts as the HTML body.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $facts Safe receipt fields in display order.
     * @return string Plain-text email body.
     */
    private function plain(array $facts): string {
        $lines = [
            $this->t('BooleanSMTP test email'),
            $this->t('Test email received'),
            '',
            $this->t('The delivery profile used for this check is recorded below.'),
            '',
            $this->t('Thank you for choosing BooleanSMTP. The details below provide a concise record of this test configuration.'),
            $this->t('Thanks,'),
            $this->t('BooleanPress'),
            '',
        ];

        foreach ($this->factGroups($facts) as $group) {
            $lines[] = $group['heading'];
            foreach ($group['facts'] as $label => $value) {
                $lines[] = $label . ': ' . $value;
            }
            $lines[] = '';
        }

        $lines[] = $this->t('This confirms only that this test reached this mailbox; it does not guarantee future inbox placement or overall deliverability.');
        $lines[] = '';
        $lines[] = $this->t('Open BooleanSMTP') . ': ' . $this->adminUrl();
        $lines[] = '--';
        $lines[] = $this->t('BooleanSMTP · WordPress email delivery');

        return implode("\n", $lines);
    }

    /**
     * Divide the safe configuration facts into compact transactional-email detail tables.
     *
     * @since 1.0.0
     *
     * @param array<string, string> $facts Safe receipt fields in their configured display order.
     * @return list<array{heading: string, facts: array<string, string>}> Titled groups for the HTML and plain-text receipts.
     */
    private function factGroups(array $facts): array {
        return [
            [
                'heading' => $this->t('Delivery details'),
                'facts'   => array_slice($facts, 0, 5, true),
            ],
            [
                'heading' => $this->t('Environment'),
                'facts'   => array_slice($facts, 5, 3, true),
            ],
        ];
    }

    /**
     * Escape a dynamic display value and add safe soft opportunities around common address separators.
     *
     * CSS word-breaking is not consistently supported by email clients. A zero-width space after
     * safe separators lets long addresses and site URLs wrap without changing their visible value.
     *
     * @since 1.0.0
     *
     * @param string $value Unescaped dynamic display value.
     * @return string HTML-safe display value with soft line-break opportunities.
     */
    private function escapeDisplayValue(string $value): string {
        if (strlen($value) <= 48) {
            return $this->escape($value);
        }

        return str_replace(
            ['@', '.', '/', '-', '_'],
            ['@&#8203;', '.&#8203;', '/&#8203;', '-&#8203;', '_&#8203;'],
            $this->escape($value)
        );
    }

    /**
     * Format an optional sender display name and address.
     *
     * @since 1.0.0
     *
     * @param string $name  Configured sender display name.
     * @param string $email Configured sender email address.
     * @return string Sender identity suitable for text or HTML escaping.
     */
    private function mailbox(string $name, string $email): string {
        return $name !== '' ? $name . ' <' . $email . '>' : $email;
    }

    /**
     * Return the BooleanSMTP test-tool URL without a placeholder link.
     *
     * @since 1.0.0
     *
     * @return string Absolute admin URL when WordPress provides one, otherwise the safe relative path.
     */
    private function adminUrl(): string {
        $path = 'admin.php?page=boolean-smtp#/tools/test';

        return \function_exists('admin_url') ? (string) \admin_url($path) : $path;
    }

    /**
     * Return the public WordPress site URL used as receipt context.
     *
     * @since 1.0.0
     *
     * @return string Configured site URL, or an empty string when WordPress is unavailable.
     */
    private function siteUrl(): string {
        return \function_exists('site_url') ? $this->oneLine((string) \site_url('/')) : '';
    }

    /**
     * Translate a static recipient-facing string.
     *
     * @since 1.0.0
     *
     * @param string               $text         Source string.
     * @param array<string, mixed> $replacements Placeholder replacements.
     * @return string Translated text.
     */
    private function t(string $text, array $replacements = []): string {
        return $this->translator->translate($text, $replacements);
    }

    /**
     * Normalize a display value to one line before rendering it into a receipt.
     *
     * @since 1.0.0
     *
     * @param string $value Raw configured or request value.
     * @return string Single-line display value.
     */
    private function oneLine(string $value): string {
        $normalized = preg_replace('/[\r\n]+/', ' ', $value);

        return trim($normalized ?? '');
    }

    /**
     * Escape a value for an HTML text node or attribute.
     *
     * @since 1.0.0
     *
     * @param string $value Unescaped value.
     * @return string HTML-safe value.
     */
    private function escape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Interpret a stored boolean setting consistently with the mailer configuration.
     *
     * @since 1.0.0
     *
     * @param mixed $value Stored setting value.
     * @return bool Whether the value represents an enabled setting.
     */
    private function isTruthy(mixed $value): bool {
        if (\is_bool($value)) {
            return $value;
        }

        if ($value === null || $value === '') {
            return false;
        }

        if (\is_int($value) || \is_float($value)) {
            return (bool) $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['1', 'yes', 'true', 'on'], true);
    }
}
