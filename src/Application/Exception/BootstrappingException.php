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
     * Factory method for a deferred service identifier claimed by more than
     * one provider class.
     *
     * @param string $serviceKey
     * @param string $existingProviderClass
     * @param string $conflictingProviderClass
     * @return self
     */
    public static function forDeferredServiceIdentifierCollision(
        string $serviceKey,
        string $existingProviderClass,
        string $conflictingProviderClass
    ): self {
        return new self(
            "Deferred service identifier [$serviceKey] is already claimed by "
            . "[$existingProviderClass] and cannot also be claimed by [$conflictingProviderClass]."
        );
    }

    /**
     * Factory method for a deferred provider resolution with no recorded
     * instance to resolve against.
     *
     * @param string $serviceKey
     * @param string $providerClass
     * @return self
     */
    public static function forMissingDeferredProviderInstance(
        string $serviceKey,
        string $providerClass
    ): self {
        return new self(
            "No registered instance found for deferred provider [$providerClass] "
            . "while resolving service [$serviceKey]."
        );
    }

    /**
     * Factory method for a service provider dependency cycle detected while
     * resolving a declarative registration/boot order.
     *
     * @param string $providerClass
     * @return self
     */
    public static function forProviderDependencyCycle(
        string $providerClass
    ): self {
        return new self(
            "Service provider dependency cycle detected involving [$providerClass]."
        );
    }

    /**
     * Factory method for a service provider declaring dependsOn() on a
     * provider class that was never registered in the same boot() cycle.
     *
     * @param string $providerClass
     * @param string $dependencyClass
     * @return self
     */
    public static function forUnknownProviderDependency(
        string $providerClass,
        string $dependencyClass
    ): self {
        return new self(
            "Service provider [$providerClass] declares a dependency on [$dependencyClass], "
            . "which was never registered."
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
