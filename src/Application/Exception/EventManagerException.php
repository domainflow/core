<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class EventManagerException extends Exception
{
    /**
     * Factory method for dispatch failures.
     *
     * @param string $event
     * @param Throwable|null $previous
     * @return self
     */
    public static function forDispatchFailure(
        string $event,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Event dispatch failed for event: {$event}",
            0,
            $previous
        );
    }
}
