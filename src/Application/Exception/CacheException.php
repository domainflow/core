<?php

declare(strict_types=1);

namespace DomainFlow\Application\Exception;

use Exception;
use Throwable;

final class CacheException extends Exception
{
    /**
     * Factory method for write failure.
     *
     * @param string $filePath
     * @param Throwable|null $previous
     * @return self
     */
    public static function forWriteFailure(
        string $filePath,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to write resolved services cache to file: $filePath",
            0,
            $previous
        );
    }

    /**
     * Factory method for read failure.
     *
     * @param string $filePath
     * @param Throwable|null $previous
     * @return self
     */
    public static function forReadFailure(
        string $filePath,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to read resolved services cache file: $filePath",
            0,
            $previous
        );
    }

    /**
     * Factory method for unserialize failure.
     *
     * @param string $filePath
     * @param string $data
     * @param Throwable|null $previous
     * @return self
     */
    public static function forUnserializeFailure(
        string $filePath,
        string $data,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Failed to unserialize resolved services cache from file: $filePath. Data: $data",
            0,
            $previous
        );
    }

    /**
     * Factory method for cache cleaning errors.
     *
     * @param Throwable $exception
     * @return self
     */
    public static function forCacheCleanedError(
        Throwable $exception
    ): self {
        return new self(
            "Error occurred while cleaning cache: " . $exception->getMessage(),
            0,
            $exception
        );
    }

    /**
     * Factory method for a generic cache error.
     *
     * @param string $message
     * @param Throwable|null $previous
     * @return self
     */
    public static function forUnknownError(
        string $message,
        ?Throwable $previous = null
    ): self {
        return new self(
            "Cache error: $message",
            0,
            $previous
        );
    }
}
