<?php

declare(strict_types=1);

namespace BooleanSmtp\Core\Exceptions;

/**
 * Handler Exception
 *
 * Thrown when there is an error in a job handler or queue processing.
 */
class HandlerException extends BooleanPressException
{
    /**
     * The job that failed.
     */
    protected ?object $job = null;

    /**
     * Set the failed job.
     */
    public function setJob(object $job): static
    {
        $this->job = $job;
        return $this;
    }

    /**
     * Get the failed job.
     */
    public function getJob(): ?object
    {
        return $this->job;
    }
}
