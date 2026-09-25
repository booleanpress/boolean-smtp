<?php
/**
 * What the six source adapters share: the sender and Custom SMTP normalisation, the yes/no
 * readers, and the "no log" defaults.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Sources;

use BooleanSmtp\Contracts\TranslatorContract;
use BooleanSmtp\Services\Migration\Contracts\SourceInterface;
use BooleanSmtp\Services\Migration\Logs\LogAvailability;
use BooleanSmtp\Services\Migration\Logs\LogWindow;
use BooleanSmtp\Services\Migration\SourceContext;
use BooleanSmtp\Services\Migration\Support\DateNormalizer;
use BooleanSmtp\Services\Migration\Support\DriverMap;

/**
 * A source without a log table inherits the "not available" answers; a source with one adds the
 * {@see Concerns\ReadsLogTable} reader.
 *
 * @since 1.0.0
 */
abstract class AbstractSource implements SourceInterface
{
    /**
     * Date-column shapes a log table can have (see {@see Concerns\ReadsLogTable}).
     *
     * @since 1.0.0
     */
    protected const DATE_LOCAL_MYSQL = 'local_mysql';
    /** @since 1.0.0 */
    protected const DATE_LOCAL_UNIX = 'local_unix';
    /** @since 1.0.0 */
    protected const DATE_UTC_MYSQL = 'utc_mysql';

    /**
     * @since 1.0.0
     *
     * @param DateNormalizer     $dates      Converts the source's timestamps to UTC.
     * @param TranslatorContract $translator Translates the names and reasons shown to the user.
     */
    public function __construct(
        protected readonly DateNormalizer $dates,
        protected readonly TranslatorContract $translator,
    ) {}

    /**
     * Whether an email log exists to import, with the reason when it does not.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return LogAvailability
     */
    public function logsAvailable(SourceContext $context): LogAvailability
    {
        return LogAvailability::no($this->noLogReason());
    }

    /**
     * Rows inside the window, before the cap.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @return int
     */
    public function countLogs(SourceContext $context, LogWindow $window): int
    {
        return 0;
    }

    /**
     * The id of the oldest row the import starts from: the cap-th newest row inside the window, or the oldest row inside it when there are fewer.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @return int|null Null when the window holds no rows.
     */
    public function logStartId(SourceContext $context, LogWindow $window): ?int
    {
        return null;
    }

    /**
     * The next chunk of rows: inside the window, with an id above `$afterId`, ascending.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @param  LogWindow     $window Date bound and cap.
     * @param  int           $afterId Last id already imported (or the start id minus one).
     * @param  int           $limit Chunk size.
     * @return list<CanonicalEmailLog>
     */
    public function extractLogs(SourceContext $context, LogWindow $window, int $afterId, int $limit): array
    {
        return [];
    }

    /**
     * Global values the source holds that become suggestions on the Review step.
     *
     * @since 1.0.0
     *
     * @param  SourceContext $context Site access.
     * @return array{retention_days?: int}
     */
    public function suggestions(SourceContext $context): array
    {
        return [];
    }

    /**
     * Why the source has no log to import: by default, because its free edition keeps none.
     *
     * @since 1.0.0
     *
     * @return string
     */
    protected function noLogReason(): string
    {
        return $this->translator->translate(
            '{{plugin}} keeps no email log in its free edition; only its Pro edition writes one.',
            ['plugin' => $this->name()]
        );
    }

    /**
     * The draft name for a source connection that has no title of its own.
     *
     * @since 1.0.0
     *
     * @param  string $sourceMailer The source plugin's mailer id.
     * @return string
     */
    protected function untitledName(string $sourceMailer): string
    {
        return $this->translator->translate('{{provider}} (imported from {{plugin}})', [
            'provider' => DriverMap::label($sourceMailer),
            'plugin'   => $this->name(),
        ]);
    }

    /**
     * The sender keys every target transport shares.
     *
     * @since 1.0.0
     *
     * @param  string $email      From address.
     * @param  string $name       From name.
     * @param  bool   $forceEmail Whether the address replaces the one WordPress or a plugin set.
     * @param  bool   $forceName  Whether the name does.
     * @param  bool   $returnPath Whether bounces go back to the From address.
     * @return array<string, mixed>
     */
    protected function sender(string $email, string $name, bool $forceEmail, bool $forceName, bool $returnPath): array
    {
        return [
            'from_email'       => trim($email),
            'from_name'        => trim($name),
            'force_from_email' => $forceEmail,
            'force_from_name'  => $forceName,
            'return_path'      => $returnPath,
        ];
    }

    /**
     * The Custom SMTP keys, normalised: the port as an integer, the encryption as
     * `none|ssl|tls`, the flags as booleans, the credential store as the database.
     *
     * @since 1.0.0
     *
     * @param  string      $host       SMTP host.
     * @param  mixed       $port       SMTP port.
     * @param  mixed       $encryption `none`, `ssl`, `tls` in any case; empty means none.
     * @param  bool        $auth       Whether the server wants a login.
     * @param  string      $username   Login name.
     * @param  string|null $password   Login password; null when not recoverable.
     * @param  bool        $autoTls    Whether STARTTLS is tried on a plain connection.
     * @param  bool        $verifyTls  Whether the server certificate is verified.
     * @return array<string, mixed>
     */
    protected function smtp(string $host, mixed $port, mixed $encryption, bool $auth, string $username, ?string $password, bool $autoTls = true, bool $verifyTls = true): array
    {
        $encryption = strtolower(trim((string) $encryption));
        if (! \in_array($encryption, ['none', 'ssl', 'tls'], true)) {
            $encryption = $encryption === '' ? 'none' : $encryption;
        }

        return [
            'host'            => trim($host),
            'port'            => (int) $port,
            'encryption'      => $encryption,
            'authentication'  => $auth,
            'username'        => trim($username),
            'password'        => $password ?? '',
            'use_auto_tls'    => $autoTls,
            'ssl_verify_peer' => $verifyTls,
            'key_store'       => 'db',
        ];
    }

    /**
     * Whether a source value means "yes": `yes`, `Yes`, `true`, `1`, `on` or a true boolean.
     *
     * @since 1.0.0
     *
     * @param  mixed $value  Source value.
     * @param  bool  $absent Returned when the value is null or an empty string.
     * @return bool
     */
    protected function yes(mixed $value, bool $absent = false): bool
    {
        if ($value === null || $value === '') {
            return $absent;
        }
        if (\is_bool($value)) {
            return $value;
        }

        return \in_array(strtolower(trim((string) $value)), ['yes', 'true', '1', 'on'], true);
    }

    /**
     * A string value from an array, trimmed.
     *
     * @since 1.0.0
     *
     * @param  array<string, mixed> $data Array.
     * @param  string               $key  Key.
     * @return string
     */
    protected function str(array $data, string $key): string
    {
        $value = $data[$key] ?? '';

        return \is_scalar($value) ? trim((string) $value) : '';
    }
}
