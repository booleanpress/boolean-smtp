<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Events;

/**
 * Base Event Class
 *
 * Optional base class for events. Provides common functionality.
 */
abstract class Event
{
    /**
     * Indicates if event propagation should be stopped.
     */
    protected bool $propagationStopped = false;

    /**
     * The time at which the event was created.
     */
    public readonly \DateTimeImmutable $occurredAt;

    /**
     * Create a new event instance.
     */
    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    /**
     * Stop the propagation of the event to further listeners.
     */
    public function stopPropagation(): void
    {
        $this->propagationStopped = true;
    }

    /**
     * Check if event propagation has been stopped.
     */
    public function isPropagationStopped(): bool
    {
        return $this->propagationStopped;
    }

    /**
     * Get the event name.
     *
     * By default, uses the class name.
     */
    public function getName(): string
    {
        return static::class;
    }
}
