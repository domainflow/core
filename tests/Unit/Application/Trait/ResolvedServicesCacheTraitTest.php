<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Exception\CacheException;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Traits\ResolvedServicesCacheTrait;
use DomainFlow\Container\Cache\ContainerCacheInterface;
use Exception;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

#[CoversClass(Application::class)]
#[CoversClass(CacheException::class)]
final class ResolvedServicesCacheTraitTest extends TestCase
{
    private DummyCache $dummy;

    protected function setUp(): void
    {
        $this->dummy = new DummyCache();
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_setCachePath_valid(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid();
        $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'cache.file';
        if (is_dir($tempDir)) {
            rmdir($tempDir);
        }

        $this->dummy->setCachePath($tempFile);
        $cachePath = $this->getProtectedProperty($this->dummy, 'cachePath');

        $this->assertSame($tempFile, $cachePath);
        $this->assertDirectoryExists($tempDir);

        rmdir($tempDir);
    }

    /**
     * @throws EventManagerException
     */
    public function test_setCachePath_mkdir_failure_vfs(): void
    {
        vfsStream::setup('root', 0555);
        $tempFile = vfsStream::url('root/nonexistent_dir/cache.file');

        $this->expectException(CacheException::class);

        $this->dummy->setCachePath($tempFile);
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_getCachePath_with_cachePath_set(): void
    {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cache.file';
        $this->setProtectedProperty($this->dummy, 'cachePath', $tempFile);
        $this->dummy->events = [];
        $result = $this->dummy->getCachePath();

        $this->assertSame($tempFile, $result);
        $this->assertNotEmpty($this->dummy->events);
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_getCachePath_without_cachePath_set(): void
    {
        $this->setProtectedProperty($this->dummy, 'cachePath', null);
        $expected = $this->dummy->basePath('cache/services.cache');
        $result = $this->dummy->getCachePath();

        $this->assertSame($expected, $result);
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_getCachePath_directory_does_not_exist(): void
    {
        $dummyNonexistent = new class() extends DummyCache {
            public function basePath(string $subPath = ''): string
            {
                $tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nonexistent_test_';

                return $subPath !== ''
                    ? $tmp . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR)
                    : $tmp;
            }
        };
        $this->setProtectedProperty($dummyNonexistent, 'cachePath', null);
        $expected = $dummyNonexistent->basePath('cache/services.cache');

        if (is_dir(dirname($expected))) {
            rmdir(dirname($expected));
        }
        $result = $dummyNonexistent->getCachePath();
        $cachePath = $this->getProtectedProperty($dummyNonexistent, 'cachePath');

        $this->assertSame($expected, $result);
        $this->assertSame($expected, $cachePath);
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_saveResolvedServicesToFile(): void
    {
        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', [
            'service1' => 'instance1',
            'service2' => 'instance2',
        ]);
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid() . '.cache';
        $this->dummy->saveResolvedServicesToFile($tempFile);
        $content = file_get_contents($tempFile);
        $unserialized = unserialize($content);
        $cacheData = $this->getProtectedProperty($this->dummy, 'resolvedServicesCache');

        $this->assertSame($cacheData, $unserialized);

        unlink($tempFile);
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_saveResolvedServicesToFile_createsDirectoryIfNotExists(): void
    {
        vfsStream::setup('root', 0777);
        $subDir = 'subdir_' . uniqid();
        $filePath = vfsStream::url('root/' . $subDir . '/services.cache');

        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', [
            'service' => 'someInstance',
        ]);
        $this->dummy->saveResolvedServicesToFile($filePath);

        $this->assertTrue(file_exists($filePath));

        $content = file_get_contents($filePath);

        $this->assertNotFalse($content);
        $this->assertSame(['service' => 'someInstance'], unserialize($content));
    }

    /**
     * @throws ReflectionException|EventManagerException
     */
    public function test_saveResolvedServicesToFile_write_failure_vfs(): void
    {
        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', ['test' => 'data']);
        vfsStream::setup('root', 0555);
        $tempDir = vfsStream::url('root');
        $tempFile = $tempDir . DIRECTORY_SEPARATOR . 'cache.file';

        set_error_handler(static function () {
        });

        $this->expectException(CacheException::class);
        try {
            $this->dummy->saveResolvedServicesToFile($tempFile);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @throws EventManagerException|CacheException|ReflectionException
     */
    public function test_loadResolvedServicesFromFile(): void
    {
        $data = ['serviceA' => 'instanceA'];
        $serialized = serialize($data);
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid() . '.cache';
        file_put_contents($tempFile, $serialized);

        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', []);
        $this->dummy->loadResolvedServicesFromFile($tempFile);
        $cacheData = $this->getProtectedProperty($this->dummy, 'resolvedServicesCache');

        $this->assertSame($data, $cacheData);

        unlink($tempFile);
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_loadResolvedServicesFromFile_read_failure(): void
    {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid() . '.cache';
        file_put_contents($tempFile, 'data');

        set_error_handler(static function () {
        });

        $this->expectException(CacheException::class);

        try {
            $this->dummy->loadResolvedServicesFromFile($tempFile);
        } finally {
            restore_error_handler();
            unlink($tempFile);
        }
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_loadResolvedServicesFromFile_fileNotExist(): void
    {
        $nonexistent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nonexistent_' . uniqid() . '.cache';
        $this->dummy->loadResolvedServicesFromFile($nonexistent);

        $this->assertSame([], $this->dummy->getResolvedServicesCache());
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_loadResolvedServicesFromFile_unserialize_failure(): void
    {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid() . '.cache';
        file_put_contents($tempFile, 'invalid serialized data');

        set_error_handler(static function () {
        });

        $this->expectException(CacheException::class);

        try {
            $this->dummy->loadResolvedServicesFromFile($tempFile);
        } finally {
            restore_error_handler();
            unlink($tempFile);
        }
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_loadResolvedServicesFromFile_read_failure_cantRead(): void
    {
        $root = vfsStream::setup('root', 0777);
        $file = new vfsStreamFile('unreadable.cache');

        $file->chmod(0000)->withContent('some content');
        $root->addChild($file);
        $filePath = $file->url();

        $this->expectException(CacheException::class);

        set_error_handler(function ($errno, $errstr) {
            return str_contains($errstr, 'file_get_contents');
        });

        try {
            $this->dummy->loadResolvedServicesFromFile($filePath);
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_clearCache(): void
    {
        $tempFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'test_cache_' . uniqid() . '.cache';
        file_put_contents($tempFile, 'data');

        $result = $this->dummy->clearCache($tempFile);

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($tempFile);
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_clearCache_fileNotExist(): void
    {
        $nonexistent = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nonexistent_' . uniqid() . '.cache';
        $result = $this->dummy->clearCache($nonexistent);

        $this->assertFalse($result);
    }

    /**
     * @throws EventManagerException|CacheException
     */
    public function test_clearCache_failure(): void
    {
        $tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'nondeletable_' . uniqid();
        mkdir($tempDir);

        set_error_handler(
            /** @throws Exception */
            static function ($errno, $errstr) {
                throw new Exception($errstr, $errno);
            }
        );
        $this->expectException(CacheException::class);

        try {
            $this->dummy->clearCache($tempDir);
        } finally {
            restore_error_handler();
            rmdir($tempDir);
        }
    }

    /**
     * @throws EventManagerException|ReflectionException
     */
    public function test_resetResolvedServicesCache(): void
    {
        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', ['a' => 1, 'b' => 2]);
        $this->dummy->resetResolvedServicesCache();

        $this->assertSame([], $this->dummy->getResolvedServicesCache());
    }

    /**
     * @throws EventManagerException|ReflectionException
     */
    public function test_updateResolvedServicesCache(): void
    {
        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', ['a' => 1, 'b' => 2]);
        $newEntries = ['b' => 3, 'c' => 4];
        $this->dummy->updateResolvedServicesCache($newEntries);
        $expected = ['a' => 1, 'b' => 3, 'c' => 4];

        $this->assertSame($expected, $this->dummy->getResolvedServicesCache());
    }

    /**
     * @throws EventManagerException
     */
    public function test_setAndLoadExternalCache(): void
    {
        $dummyCache = new DummyExternalCache();
        $dummyCache->set('key1', ['x' => 'y']);
        $this->dummy->setExternalCache($dummyCache);
        $this->dummy->loadResolvedServicesFromExternalCache('key1');

        $this->assertSame(['x' => 'y'], $this->dummy->getResolvedServicesCache());
    }

    /**
     * @throws ReflectionException
     */
    public function test_getResolvedServicesCache(): void
    {
        $this->setProtectedProperty($this->dummy, 'resolvedServicesCache', ['one' => 'two']);

        $this->assertSame(['one' => 'two'], $this->dummy->getResolvedServicesCache());
    }

    /**
     * @throws ReflectionException
     */
    public function test_isCachingEnabled(): void
    {
        putenv('CONTAINER_CACHE=true');
        $isEnabled = $this->callProtectedMethod($this->dummy);

        $this->assertTrue($isEnabled);

        putenv('CONTAINER_CACHE=false');
        $isEnabled = $this->callProtectedMethod($this->dummy);

        $this->assertFalse($isEnabled);
    }

    /**
     * @throws ReflectionException|CacheException|EventManagerException
     */
    public function test_getCachePath_returns_default_when_directory_exists(): void
    {
        $dummy = new DummyCache();

        $defaultPath = $dummy->basePath('cache/services.cache');
        $defaultDir = dirname($defaultPath);
        if (!is_dir($defaultDir)) {
            mkdir($defaultDir, 0777, true);
        }

        $this->setProtectedProperty($dummy, 'cachePath', null);
        $dummy->events = [];
        $result = $dummy->getCachePath();

        $this->assertSame($defaultPath, $result);
        $this->assertEmpty($dummy->events, 'No event should be fired if cachePath was not set and directory existed.');
    }

    /**
     * @throws ReflectionException
     */
    private function getProtectedProperty(
        object $object,
        string $property
    ): mixed {
        $refClass = new ReflectionClass($object);
        $refProp = $refClass->getProperty($property);

        return $refProp->getValue($object);
    }

    /**
     * @throws ReflectionException
     */
    private function setProtectedProperty(
        object $object,
        string $property,
        mixed $value
    ): void {
        $refClass = new ReflectionClass($object);
        $refProp = $refClass->getProperty($property);
        $refProp->setValue($object, $value);
    }

    /**
     * @throws ReflectionException
     */
    private function callProtectedMethod(
        object $object
    ): mixed {
        $args = [];
        $refClass = new ReflectionClass($object);
        $refMethod = $refClass->getMethod('isCachingEnabled');

        return $refMethod->invokeArgs($object, $args);
    }
}

// Dummy classes
class DummyCache
{
    use ResolvedServicesCacheTrait;

    public array $events = [];
    public ?ContainerCacheInterface $externalCache = null;

    public function basePath(
        string $subPath = ''
    ): string {
        $tmpDir = sys_get_temp_dir();

        return $subPath !== '' ? $tmpDir . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR) : $tmpDir;
    }

    public function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->events[] = [$event, $args];
    }
}

class DummyExternalCache implements ContainerCacheInterface
{
    private array $store = [];

    public function has(
        string $key
    ): bool {
        return isset($this->store[$key]);
    }

    public function get(
        string $key
    ): mixed {
        return $this->store[$key] ?? null;
    }

    public function set(
        string $key,
        mixed $value,
        int $ttl = 3600
    ): bool {
        $this->store[$key] = $value;

        return true;
    }

    public function delete(
        string $key
    ): bool {
        unset($this->store[$key]);

        return true;
    }
}

class DummyCacheSimMkdirFail extends DummyCache
{
    public function setCachePath(
        string $path
    ): void {
        $cacheDir = dirname($path);
        $this->fireEvent('cache.directory.creation.error', $cacheDir);
        throw CacheException::forUnknownError("Failed to create cache directory: $cacheDir");
    }
}

class DummyCacheSimWriteFail extends DummyCache
{
    public function saveResolvedServicesToFile(
        ?string $filePath = null
    ): void {
        $filePath = $filePath ?? $this->getCachePath();
        $serialized = serialize($this->resolvedServicesCache);
        throw CacheException::forWriteFailure($filePath);
    }
}

class DummyCacheNonexistentDir extends DummyCache
{
    private array $cachedBasePath = [];

    public function basePath(
        string $subPath = ''
    ): string {
        if (!isset($this->cachedBasePath[$subPath])) {
            $this->cachedBasePath[$subPath] = '/nonexistent_directory_' . uniqid() . DIRECTORY_SEPARATOR . $subPath;
        }

        return $this->cachedBasePath[$subPath];
    }
}

class DummyCacheReadFail extends DummyCache
{
    public function loadResolvedServicesFromFile(
        string $filePath
    ): void {
        if (!file_exists($filePath)) {
            return;
        }
        throw CacheException::forReadFailure($filePath);
    }
}
