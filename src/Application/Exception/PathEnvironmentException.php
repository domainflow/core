<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class PathEnvironmentException extends Exception
{
    /**
     * Factory method for an invalid base path.
     *
     * @param string $path
     * @param Throwable|null $previous
     * @return self
     */
    public static function forInvalidBasePath(
        string $path,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Invalid base path provided: {$path}",
            0,
            $previous
        );
    }

    /**
     * Factory method for an invalid configuration path.
     *
     * @param string $path
     * @param Throwable|null $previous
     * @return self
     */
    public static function forInvalidConfigPath(
        string $path,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Invalid configuration path provided: {$path}",
            0,
            $previous
        );
    }
}
