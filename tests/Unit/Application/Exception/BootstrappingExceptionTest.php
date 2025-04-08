<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\BootstrappingException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BootstrappingException::class)]
final class BootstrappingExceptionTest extends TestCase
{
    public function test_forProviderRegistrationFailure(): void
    {
        $providerClass = 'SomeProvider';
        $previous = new Exception('Previous error');
        $exception = BootstrappingException::forProviderRegistrationFailure($providerClass, $previous);

        $this->assertSame("Failed to register service provider: $providerClass", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forBootCallbackFailure(): void
    {
        $callbackDescription = 'Test callback';
        $previous = new Exception('Callback error');
        $exception = BootstrappingException::forBootCallbackFailure($callbackDescription, $previous);

        $this->assertSame("Boot callback failed: $callbackDescription", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forDeferredProviderLoadError(): void
    {
        $serviceKey = 'service1';
        $providerClass = 'ProviderClass';
        $previous = new Exception('Deferred load error');
        $exception = BootstrappingException::forDeferredProviderLoadError($serviceKey, $providerClass, $previous);

        $this->assertSame(
            "Failed to load deferred provider for service [$serviceKey] from [$providerClass]",
            $exception->getMessage()
        );
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forGenericError(): void
    {
        $message = 'Generic error occurred';
        $previous = new Exception('Generic exception');
        $exception = BootstrappingException::forGenericError($message, $previous);

        $this->assertSame("Bootstrapping error: $message", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
