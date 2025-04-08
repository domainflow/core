<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\PathEnvironmentException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PathEnvironmentException::class)]
final class PathEnvironmentExceptionTest extends TestCase
{
    public function test_forInvalidBasePath(): void
    {
        $path = '/invalid/base/path';
        $previous = new Exception('Base path error');
        $exception = PathEnvironmentException::forInvalidBasePath($path, $previous);

        $this->assertSame("Invalid base path provided: {$path}", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forInvalidConfigPath(): void
    {
        $path = '/invalid/config/path';
        $previous = new Exception('Config path error');
        $exception = PathEnvironmentException::forInvalidConfigPath($path, $previous);

        $this->assertSame("Invalid configuration path provided: {$path}", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
