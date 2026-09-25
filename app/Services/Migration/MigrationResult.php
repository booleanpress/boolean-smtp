<?php
/**
 * The outcome of one import run: the assessed connections, the log progress, suggestions.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration;

use BooleanSmtp\Contracts\EncryptorContract;
use BooleanSmtp\Services\Migration\Assessment\ConnectionAssessment;

/**
 * Returned by {@see MigrationRunner::run()} and shaped into the REST and CLI responses by
 * {@see toArray()}.
 *
 * @since 1.0.0
 */
final class MigrationResult
{
    /**
     * @since 1.0.0
     *
     * @param string                     $source      Source id.
     * @param bool                       $dryRun      Whether nothing was written.
     * @param list<ConnectionAssessment> $connections Every source connection, assessed.
     * @param array<string, mixed>       $logs        Log availability and progress: `available`, `reason`, `total`, `imported`, `skipped`, `cursor`, `done`.
     * @param array<string, mixed>       $suggestions Review-step suggestions from the source's global settings.
     * @param list<string>               $errors      Errors that stopped part of the run.
     */
    public function __construct(
        public readonly string $source,
        public readonly bool $dryRun,
        public readonly array $connections = [],
        public readonly array $logs = [],
        public readonly array $suggestions = [],
        public readonly array $errors = [],
    ) {}

    /**
     * A run that could not start.
     *
     * @since 1.0.0
     *
     * @param  string $source Source id.
     * @param  string $error  Why.
     * @return self
     */
    public static function error(string $source, string $error): self
    {
        return new self($source, true, [], [], [], [$error]);
    }

    /**
     * Whether nothing went wrong.
     *
     * @since 1.0.0
     *
     * @return bool
     */
    public function ok(): bool
    {
        return $this->errors === [];
    }

    /**
     * Array form for the REST and CLI responses; credentials masked.
     *
     * @since 1.0.0
     *
     * @param  EncryptorContract $encryptor Decides which settings keys are credentials.
     * @return array<string, mixed>
     */
    public function toArray(EncryptorContract $encryptor): array
    {
        $connections = array_map(
            static fn (ConnectionAssessment $a): array => $a->toArray($encryptor),
            $this->connections
        );

        return [
            'success'     => $this->ok(),
            'source'      => $this->source,
            'dry_run'     => $this->dryRun,
            'connections' => $connections,
            'logs'        => $this->logs,
            'suggestions' => $this->suggestions,
            'errors'      => $this->errors,
        ];
    }
}
