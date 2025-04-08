<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class TerminationException extends Exception
{
    /**
     * Factory method for termination callback failures.
     *
     * @param Throwable $previous
     * @return self
     */
    public static function forCallbackFailure(
        Throwable $previous
    ): self {
        return new self(
            "A termination callback failed.",
            0,
            $previous
        );
    }
}
