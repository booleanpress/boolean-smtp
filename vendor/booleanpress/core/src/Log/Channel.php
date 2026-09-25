<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Log;

use BooleanSmtp\Core\Contracts\LoggerContract;

/**
 * One named log channel, usable anywhere a {@see LoggerContract} is expected.
 */
class Channel implements LoggerContract
{
    public function __construct(
        protected LogManager $manager,
        protected string $name,
    ) {
    }

    /**
     * The channel's name.
     */
    public function name(): string
    {
        return $this->name;
    }

    /**
     * Whether the channel writes at all.
     */
    public function isEnabled(): bool
    {
        return $this->manager->enabled($this->name);
    }

    /**
     * Append a preformatted, possibly multi-line block.
     */
    public function append(string $block): void
    {
        $this->manager->append($this->name, $block);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function log(string $level, string|\Stringable $message, array $context = []): void
    {
        $this->manager->write($this->name, $level, (string) $message, $context);
    }

    public function emergency(string $message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert(string $message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical(string $message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice(string $message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }
}
