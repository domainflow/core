<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Exception;

use DomainFlow\Application\Exception\CacheException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(CacheException::class)]
final class CacheExceptionTest extends TestCase
{
    public function test_forWriteFailure(): void
    {
        $filePath = '/path/to/cache.file';
        $previous = new Exception('Write error');
        $exception = CacheException::forWriteFailure($filePath, $previous);

        $this->assertSame("Failed to write resolved services cache to file: $filePath", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forReadFailure(): void
    {
        $filePath = '/path/to/cache.file';
        $previous = new Exception('Read error');
        $exception = CacheException::forReadFailure($filePath, $previous);

        $this->assertSame("Failed to read resolved services cache file: $filePath", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forUnserializeFailure(): void
    {
        $filePath = '/path/to/cache.file';
        $data = 'invalid serialized data';
        $previous = new Exception('Unserialize error');
        $exception = CacheException::forUnserializeFailure($filePath, $data, $previous);

        $expectedMessage = "Failed to unserialize resolved services cache from file: $filePath. Data: $data";
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }

    public function test_forCacheCleanedError(): void
    {
        $innerException = new Exception('Clean error occurred');
        $exception = CacheException::forCacheCleanedError($innerException);

        $expectedMessage = "Error occurred while cleaning cache: " . $innerException->getMessage();
        $this->assertSame($expectedMessage, $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($innerException, $exception->getPrevious());
    }

    public function test_forUnknownError(): void
    {
        $message = 'Some unknown cache error';
        $previous = new Exception('Generic error');
        $exception = CacheException::forUnknownError($message, $previous);

        $this->assertSame("Cache error: $message", $exception->getMessage());
        $this->assertSame(0, $exception->getCode());
        $this->assertSame($previous, $exception->getPrevious());
    }
}
