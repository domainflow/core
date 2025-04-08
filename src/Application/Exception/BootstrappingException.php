<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

/**
 * Class BootstrappingException
 *
 * An exception thrown when bootstrapping the application fails.
 */
final class BootstrappingException extends Exception
{
    /**
     * Factory method for provider registration failures.
     *
     * @param string $providerClass
     * @param Throwable|null $previous
     * @return self
     */
    public static function forProviderRegistrationFailure(
        string $providerClass,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to register service provider: $providerClass",
            0,
            $previous
        );
    }

    /**
     * Factory method for boot callback failures.
     *
     * @param string $callbackDescription
     * @param Throwable|null $previous
     * @return self
     */
    public static function forBootCallbackFailure(
        string $callbackDescription,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Boot callback failed: $callbackDescription",
            0,
            $previous
        );
    }

    /**
     * Factory method for deferred provider load errors.
     *
     * @param string $serviceKey
     * @param string $providerClass
     * @param Throwable|null $previous
     * @return self
     */
    public static function forDeferredProviderLoadError(
        string $serviceKey,
        string $providerClass,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to load deferred provider for service [$serviceKey] from [$providerClass]",
            0,
            $previous
        );
    }

    /**
     * Factory method for generic bootstrapping errors.
     *
     * @param string $message
     * @param Throwable|null $previous
     * @return self
     */
    public static function forGenericError(
        string $message,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Bootstrapping error: $message",
            0,
            $previous
        );
    }
}
