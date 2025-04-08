<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class ServiceDefinitionLoaderException extends Exception
{
    public static function forInvalidDefinition(
        string $abstract,
        string $message,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Invalid service definition for [$abstract]: " . $message,
            0,
            $previous
        );
    }

    public static function forDefinitionProcessingFailure(
        string $abstract,
        Throwable $previous
    ): self {
        return new self(
            "Failed to process service definition for [$abstract]: "
            . $previous->getMessage(),
            0,
            $previous
        );
    }
}
