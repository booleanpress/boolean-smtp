<?php
/**
 * Decides what happens to each source connection: imported as is, imported without its
 * credential, converted to an SMTP relay, or refused with a reason.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Assessment;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Services\Mailer\MailerManager;
use BooleanSmtp\Services\Migration\Canonical\CanonicalConnection;
use BooleanSmtp\Services\Migration\Support\DriverMap;
use BooleanSmtp\Services\Migration\Support\SmtpRelayConverter;

/**
 * The rules, in order: a mailer without a driver is unsupported; SendGrid and Postmark become
 * Custom SMTP relays; a driver the free plugin does not launch is unsupported; a credential the
 * source could not hand over, or that the target transport rejects, means "needs password";
 * any other validation finding means "needs review"; Google and Microsoft need an
 * authorisation; the rest is ready.
 *
 * @since 1.0.0
 */
final class ConnectionAssessor
{
    /**
     * Drivers the free plugin launches with — the same five the setup wizard offers.
     *
     * @since 1.0.0
     * @var list<string>
     */
    public const SUPPORTED_DRIVERS = ['smtp', 'ses', 'google', 'outlook', 'php'];

    /**
     * Drivers whose connection ends with the user authorising an account.
     *
     * @since 1.0.0
     * @var list<string>
     */
    private const OAUTH_DRIVERS = ['google', 'outlook'];

    /**
     * @since 1.0.0
     *
     * @param MailerManager      $mailer     Resolves the target transport for its `validateSettings()`.
     * @param EncryptorContract  $encryptor  Tells credential keys from the rest.
     * @param TranslatorContract $translator Translates the reasons shown to the user.
     */
    public function __construct(
        private readonly MailerManager $mailer,
        private readonly EncryptorContract $encryptor,
        private readonly TranslatorContract $translator,
    ) {}

    /**
     * Assess one connection.
     *
     * @since 1.0.0
     *
     * @param  CanonicalConnection $connection As the source read it.
     * @return ConnectionAssessment
     */
    public function assess(CanonicalConnection $connection): ConnectionAssessment
    {
        if ($connection->unsupportedReason !== null || $connection->driver === null) {
            return $this->unsupported($connection, $connection->unsupportedReason ?? $this->manualSetupReason($connection->sourceMailer));
        }

        $conversion = null;
        if (SmtpRelayConverter::converts($connection->driver)) {
            $conversion = SmtpRelayConverter::conversion($connection->driver);
            $connection = SmtpRelayConverter::convert($connection);
        }

        if (! \in_array($connection->driver, self::SUPPORTED_DRIVERS, true)) {
            return $this->unsupported($connection, $this->manualSetupReason($connection->sourceMailer));
        }

        $errors  = $this->mailer->resolveTransportByDriver((string) $connection->driver)->validateSettings($connection->settings);
        $missing = $connection->missingSecrets;
        $issues  = [];
        foreach ($errors as $key => $message) {
            $key = (string) $key;
            if ($this->encryptor->isSensitiveKey($key)) {
                $missing[] = $key;
            } else {
                $issues[] = ['key' => $key, 'message' => (string) $message];
            }
        }
        $missing = array_values(array_unique($missing));

        $status = ConnectionAssessment::READY;
        if ($missing !== []) {
            $status = ConnectionAssessment::NEEDS_PASSWORD;
        } elseif ($issues !== []) {
            $status = ConnectionAssessment::NEEDS_REVIEW;
        } elseif (\in_array($connection->driver, self::OAUTH_DRIVERS, true)) {
            $status = ConnectionAssessment::NEEDS_AUTHORIZATION;
        }

        return new ConnectionAssessment(
            source: $connection->sourcePlugin,
            sourceKey: $connection->sourceKey,
            name: $connection->name,
            driver: $connection->driver,
            sourceMailer: $connection->sourceMailer,
            settings: $connection->settings,
            status: $status,
            missing: $missing,
            issues: $issues,
            conversion: $conversion,
            wasDefault: $connection->wasDefault,
        );
    }

    /**
     * The `unsupported` assessment for a connection.
     *
     * @since 1.0.0
     *
     * @param  CanonicalConnection $connection The connection.
     * @param  string              $reason     Plain sentence for the user.
     * @return ConnectionAssessment
     */
    private function unsupported(CanonicalConnection $connection, string $reason): ConnectionAssessment
    {
        return new ConnectionAssessment(
            source: $connection->sourcePlugin,
            sourceKey: $connection->sourceKey,
            name: $connection->name,
            driver: null,
            sourceMailer: $connection->sourceMailer,
            settings: [],
            status: ConnectionAssessment::UNSUPPORTED,
            wasDefault: $connection->wasDefault,
            reason: $reason,
        );
    }

    /**
     * The "set up manually" sentence for a mailer the free plugin does not launch.
     *
     * @since 1.0.0
     *
     * @param  string $sourceMailer The source plugin's mailer id.
     * @return string
     */
    private function manualSetupReason(string $sourceMailer): string
    {
        return $this->translator->translate(
            '{{mailer}} is not available in this version. Choose Custom SMTP and use the provider\'s SMTP relay credentials; its API key is not copied.',
            ['mailer' => DriverMap::label($sourceMailer)]
        );
    }
}
