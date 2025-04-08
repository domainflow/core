<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\EventManagerException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventManagerException::class)]
final class EventManagerExceptionTest extends TestCase
{
    public function test_forDispatchFailure(): void
    {
        $event = 'test.event';
        $previous = new Exception('Dispatch failure');
        $exception = EventManagerException::forDispatchFailure($event, $previous);

        $this->assertSame("Event dispatch failed for event: {$event}", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
