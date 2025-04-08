<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\CacheException;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Container\Cache\ContainerCacheInterface;
use Exception;
use RuntimeException;

/**
 * Trait ResolvedServicesCacheTrait
 *
 * Caching trait for resolved services.
 */
trait ResolvedServicesCacheTrait
{
    private const string CACHE_FOLDER_KEY = 'cache/services.cache';
    private const string EVENT_CACHE_DIRECTORY_CREATED_KEY = 'cache.directory.created';
    private const string EVENT_CACHE_DIRECTORY_CREATION_ERROR_KEY = 'cache.directory.creation.error';
    private const string EVENT_CACHE_SET_CACHE_PATH = 'cache.cache.path';
    private const string EVENT_CACHE_CLEANED_KEY = 'cache.cleaned';
    private const string EVENT_CACHE_CLEANED_ERROR_KEY = 'cache.cleaned.error';
    private const string EVENT_CACHE_SAVED_KEY = 'cache.saved';
    private const string EVENT_CACHE_LOADED_KEY = 'cache.loaded';
    private const string EVENT_EXTERNAL_CACHE_SET_KEY = 'cache.external.set';
    private const string EVENT_EXTERNAL_CACHE_LOADED_KEY = 'cache.external.loaded';
    private const string EVENT_CACHE_INVALIDATED_KEY = 'cache.invalidated';
    private const string EVENT_CACHE_UPDATED_KEY = 'cache.updated';

    /**
     * The file path where the service cache should be saved.
     *
     * @var string|null
     */
    protected ?string $cachePath = null;

    /**
     * The in-memory resolved services cache.
     *
     * @var array<string, mixed>
     */
    protected array $resolvedServicesCache = [];

    /**
     * Set the custom cache file path.
     *
     * @param string $path
     * @throws CacheException|EventManagerException
     * @return void
     */
    public function setCachePath(
        string $path
    ): void {
        $this->cachePath = $path;
        $cacheDir = dirname($this->cachePath);

        if (!is_dir($cacheDir)) {
            if (!mkdir($cacheDir, 0777, true)) {
                $this->fireEvent(self::EVENT_CACHE_DIRECTORY_CREATION_ERROR_KEY, $cacheDir);
                throw CacheException::forUnknownError("Failed to create cache directory: $cacheDir");
            }
            $this->fireEvent(self::EVENT_CACHE_DIRECTORY_CREATED_KEY, $cacheDir);
        }
    }

    /**
     * Get the cache file path, using a default if not set.
     *
     * @throws CacheException|EventManagerException
     * @return string
     */
    public function getCachePath(): string
    {
        if ($this->cachePath !== null) {
            $this->fireEvent(self::EVENT_CACHE_SET_CACHE_PATH, $this->cachePath);

            return (string) $this->cachePath;
        }

        $defaultPath = $this->basePath(self::CACHE_FOLDER_KEY);
        if (!is_dir(dirname($defaultPath))) {
            $this->setCachePath($defaultPath);

            // Ensure cachePath is now set.
            return $this->cachePath ?? $defaultPath;
        }

        return $defaultPath;
    }

    /**
     * Save the resolved services cache to a file.
     *
     * @param string|null $filePath
     * @throws CacheException|EventManagerException
     * @return void
     */
    public function saveResolvedServicesToFile(
        ?string $filePath = null
    ): void {
        $filePath = $filePath ?? $this->getCachePath();

        $serialized = serialize($this->resolvedServicesCache);

        if (!is_dir(dirname($filePath))) {
            $this->setCachePath($filePath);
        }

        if (file_put_contents($filePath, $serialized) === false) {
            throw CacheException::forWriteFailure($filePath);
        }

        $this->fireEvent(self::EVENT_CACHE_SAVED_KEY, $filePath);
    }

    /**
     * Load the resolved services cache from a file.
     *
     * @param string $filePath
     * @throws RuntimeException|EventManagerException|CacheException
     * @return void
     */
    public function loadResolvedServicesFromFile(
        string $filePath
    ): void {
        if (!file_exists($filePath)) {
            return;
        }
        $data = file_get_contents($filePath);
        if ($data === false) {
            throw CacheException::forReadFailure($filePath);
        }
        $cache = @unserialize($data, ['allowed_classes' => true]);
        if (!is_array($cache)) {
            throw CacheException::forUnserializeFailure($filePath, $data);
        }
        /** @var array<string, mixed> $cache */
        $this->resolvedServicesCache = $cache;
        $this->fireEvent(self::EVENT_CACHE_LOADED_KEY, $filePath, count($cache));
    }

    /**
     * Clear the services cache file.
     *
     * @param string|null $filePath
     * @throws CacheException|EventManagerException
     * @return bool
     */
    public function clearCache(
        ?string $filePath = null
    ): bool {
        $filePath = $filePath ?? $this->getCachePath();
        if (file_exists($filePath)) {
            try {
                $status = unlink($filePath);
                $this->fireEvent(self::EVENT_CACHE_CLEANED_KEY, $status);

                return $status;
            } catch (Exception $exception) {
                $this->fireEvent(self::EVENT_CACHE_CLEANED_ERROR_KEY, $exception);
                throw CacheException::forCacheCleanedError($exception);
            }
        }

        return false;
    }

    /**
     * Reset the in-memory resolved services cache.
     *
     * @throws EventManagerException
     * @return void
     */
    public function resetResolvedServicesCache(): void
    {
        $this->resolvedServicesCache = [];
        $this->fireEvent(self::EVENT_CACHE_INVALIDATED_KEY);
    }

    /**
     * Merge new entries into the in-memory resolved services cache.
     *
     * @param array<string, mixed> $newEntries
     * @throws EventManagerException
     * @return void
     */
    public function updateResolvedServicesCache(
        array $newEntries
    ): void {
        /** @var array<string, mixed> $merged */
        $merged = array_replace($this->resolvedServicesCache, $newEntries);
        $this->resolvedServicesCache = $merged;
        $this->fireEvent(self::EVENT_CACHE_UPDATED_KEY, count($this->resolvedServicesCache), $newEntries);
    }

    /**
     * Get the current in-memory resolved services cache.
     *
     * @return array<string, mixed>
     */
    public function getResolvedServicesCache(): array
    {
        return $this->resolvedServicesCache;
    }

    /**
     * Set the external cache implementation.
     *
     * @param ContainerCacheInterface $cacheStore
     * @throws EventManagerException
     * @return void
     */
    public function setExternalCache(
        ContainerCacheInterface $cacheStore
    ): void {
        $this->externalCache = $cacheStore;
        $this->fireEvent(self::EVENT_EXTERNAL_CACHE_SET_KEY, get_class($cacheStore));
    }

    /**
     * Load the resolved services cache from the external cache store.
     *
     * @param string $cacheKey
     * @throws EventManagerException
     * @return void
     */
    public function loadResolvedServicesFromExternalCache(
        string $cacheKey
    ): void {
        if ($this->externalCache !== null && $this->externalCache->has($cacheKey)) {
            $cached = $this->externalCache->get($cacheKey);
            if (is_array($cached)) {
                /** @var array<string, mixed> $cached */
                $this->resolvedServicesCache = $cached;
                $this->fireEvent(self::EVENT_EXTERNAL_CACHE_LOADED_KEY, $cacheKey, count($cached));
            }
        }
    }

    /**
     * Determine if container caching is enabled.
     *
     * @return bool
     */
    protected function isCachingEnabled(): bool
    {
        return getenv('CONTAINER_CACHE') === 'true';
    }
}
