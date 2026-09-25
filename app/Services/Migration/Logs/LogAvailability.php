<?php
/**
 * Whether a source has an email log to import, and why not when it has none.
 *
 * @package BooleanSmtp
 * @since   1.0.0
 */

declare(strict_types=1);

namespace BooleanSmtp\Services\Migration\Logs;

/**
 * Three of the six sources keep no email log in their free edition; the UI says so instead of
 * offering a checkbox.
 *
 * @since 1.0.0
 */
final class LogAvailability
{
    /**
     * @since 1.0.0
     *
     * @param bool        $available Whether the source's log table exists on this site.
     * @param string|null $reason    Plain sentence for the user when it does not.
     */
    public function __construct(
        public readonly bool $available,
        public readonly ?string $reason = null,
    ) {}

    /**
     * An available log.
     *
     * @since 1.0.0
     *
     * @return self
     */
    public static function yes(): self
    {
        return new self(true);
    }

    /**
     * A source without a log, with the reason.
     *
     * @since 1.0.0
     *
     * @param  string $reason Plain sentence for the user.
     * @return self
     */
    public static function no(string $reason): self
    {
        return new self(false, $reason);
    }

    /**
     * Array form for the REST and CLI responses.
     *
     * @since 1.0.0
     *
     * @return array{available: bool, reason: string|null}
     */
    public function toArray(): array
    {
        return ['available' => $this->available, 'reason' => $this->reason];
    }
}
