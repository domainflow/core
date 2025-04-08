<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class MiddlewareException extends Exception
{
    /**
     * Factory method for pipeline failure.
     *
     * @param string $message
     * @param Throwable|null $previous
     * @return self
     */
    public static function forPipelineFailure(
        string $message,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Middleware pipeline failure: " . $message,
            0,
            $previous
        );
    }
}
