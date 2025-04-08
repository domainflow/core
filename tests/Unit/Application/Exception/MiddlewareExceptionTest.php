<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\MiddlewareException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(MiddlewareException::class)]
final class MiddlewareExceptionTest extends TestCase
{
    public function test_forPipelineFailure(): void
    {
        $message = 'Test pipeline error';
        $previous = new Exception('Underlying error');
        $exception = MiddlewareException::forPipelineFailure($message, $previous);

        $expectedMessage = "Middleware pipeline failure: " . $message;
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
