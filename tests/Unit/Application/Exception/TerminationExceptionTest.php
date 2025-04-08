<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\TerminationException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TerminationException::class)]
final class TerminationExceptionTest extends TestCase
{
    public function test_forCallbackFailure(): void
    {
        $previous = new Exception('Termination failure');
        $exception = TerminationException::forCallbackFailure($previous);

        $this->assertSame("A termination callback failed.", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
