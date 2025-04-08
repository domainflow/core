<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\ServiceDefinitionLoaderException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ServiceDefinitionLoaderException::class)]
final class ServiceDefinitionLoaderExceptionTest extends TestCase
{
    public function test_forInvalidDefinition(): void
    {
        $abstract = 'MyService';
        $message = 'Invalid configuration';
        $previous = new Exception('Underlying error');
        $exception = ServiceDefinitionLoaderException::forInvalidDefinition($abstract, $message, $previous);

        $expectedMessage = "Invalid service definition for [{$abstract}]: " . $message;
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forDefinitionProcessingFailure(): void
    {
        $abstract = 'MyService';
        $previous = new Exception('Processing error');
        $exception = ServiceDefinitionLoaderException::forDefinitionProcessingFailure($abstract, $previous);

        $expectedMessage = "Failed to process service definition for [{$abstract}]: " . $previous->getMessage();
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
