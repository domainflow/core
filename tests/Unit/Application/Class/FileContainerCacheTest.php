<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Class;

use DomainFlow\Application\Class\FileContainerCache;
use DomainFlow\Application\Exception\CacheException;
use org\bovigo\vfs\vfsStream;
use org\bovigo\vfs\vfsStreamFile;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

#[CoversClass(FileContainerCache::class)]
#[CoversClass(CacheException::class)]
final class FileContainerCacheTest extends TestCase
{
    private string $tempDir;
    private string $file;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'file-container-cache-test-' . uniqid();
        $this->file = $this->tempDir . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'definitions.cache';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            $this->removeDirectory($this->tempDir);
        }
    }

    private function removeDirectory(string $dir): void
    {
        $items = scandir($dir);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }
        rmdir($dir);
    }

    /**
     * @throws CacheException
     */
    public function test_get_returns_null_when_file_missing(): void
    {
        $cache = new FileContainerCache($this->file);

        $this->assertNull($cache->get('missing'));
        $this->assertFalse($cache->has('missing'));
    }

    /**
     * @throws CacheException
     */
    public function test_set_then_get_round_trips_declarative_data(): void
    {
        $cache = new FileContainerCache($this->file);
        $value = [
            'version' => 1,
            'bindings' => ['MyService' => ['concrete' => 'MyService', 'shared' => true]],
            'aliases' => [],
        ];

        $result = $cache->set('key1', $value, 0);

        $this->assertTrue($result);
        $this->assertTrue($cache->has('key1'));
        $this->assertSame($value, $cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_set_creates_missing_directory(): void
    {
        $this->assertDirectoryDoesNotExist(dirname($this->file));

        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);

        $this->assertDirectoryExists(dirname($this->file));
        $this->assertFileExists($this->file);
    }

    /**
     * @throws CacheException
     */
    public function test_set_writes_file_with_restrictive_permissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not applicable on Windows.');
        }

        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);

        $permissions = fileperms($this->file) & 0777;
        $this->assertSame(0600, $permissions);
    }

    /**
     * @throws CacheException
     */
    public function test_ttl_zero_never_expires(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);

        $stored = json_decode((string) file_get_contents($this->file), true);
        $this->assertNull($stored['entries']['key1']['expiresAt']);
    }

    /**
     * @throws CacheException
     */
    public function test_expired_entry_is_treated_as_a_miss(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 3600);

        // Rewrite the entry with an expiry in the past to simulate elapsed time.
        $stored = json_decode((string) file_get_contents($this->file), true);
        $stored['entries']['key1']['expiresAt'] = time() - 1;
        file_put_contents($this->file, json_encode($stored));

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_malformed_json_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, '{not valid json');

        $cache = new FileContainerCache($this->file);

        $this->assertNull($cache->get('key1'));
        $this->assertFalse($cache->has('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_unexpected_version_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 2,
            'entries' => ['key1' => ['value' => ['a' => 1], 'expiresAt' => null]],
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_unexpected_shape_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => ['key1' => ['value' => ['a' => 1]]], // missing expiresAt
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_tampered_extra_top_level_field_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [],
            'unexpected' => 'field',
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertFalse($cache->has('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_delete_missing_key_is_a_no_op(): void
    {
        $cache = new FileContainerCache($this->file);

        $this->assertTrue($cache->delete('missing'));
    }

    /**
     * @throws CacheException
     */
    public function test_delete_removes_file_when_store_becomes_empty(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);

        $result = $cache->delete('key1');

        $this->assertTrue($result);
        $this->assertFileDoesNotExist($this->file);
    }

    /**
     * @throws CacheException
     */
    public function test_delete_preserves_other_keys(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);
        $cache->set('key2', ['b' => 2], 0);

        $cache->delete('key1');

        $this->assertFalse($cache->has('key1'));
        $this->assertTrue($cache->has('key2'));
        $this->assertSame(['b' => 2], $cache->get('key2'));
    }

    public function test_set_directory_creation_failure_throws(): void
    {
        vfsStream::setup('root', 0555);
        $file = vfsStream::url('root/nested/definitions.cache');

        $cache = new FileContainerCache($file);

        $this->expectException(CacheException::class);
        $cache->set('key1', ['a' => 1], 0);
    }

    /**
     * @throws CacheException
     */
    public function test_delete_unlink_failure_throws(): void
    {
        $root = vfsStream::setup('root', 0555);
        vfsStream::newFile('definitions.cache')
            ->withContent(json_encode([
                'version' => 1,
                'entries' => ['key1' => ['value' => ['a' => 1], 'expiresAt' => null]],
            ]))
            ->at($root);
        $file = vfsStream::url('root/definitions.cache');

        $cache = new FileContainerCache($file);

        $this->expectException(CacheException::class);
        $cache->delete('key1');
    }

    /**
     * @throws CacheException
     */
    public function test_unreadable_file_is_treated_as_a_miss(): void
    {
        $root = vfsStream::setup('root', 0777);
        $file = new vfsStreamFile('definitions.cache');
        $file->chmod(0000)->withContent(json_encode([
            'version' => 1,
            'entries' => ['key1' => ['value' => ['a' => 1], 'expiresAt' => null]],
        ]));
        $root->addChild($file);

        $cache = new FileContainerCache($file->url());

        $this->assertNull($cache->get('key1'));
        $this->assertFalse($cache->has('key1'));
    }

    public function test_set_rejects_non_json_encodable_value(): void
    {
        $cache = new FileContainerCache($this->file);

        $this->expectException(CacheException::class);
        $cache->set('key1', NAN, 0);
    }

    public function test_set_write_failure_throws_when_quota_exceeded(): void
    {
        vfsStream::setup('root', 0777);
        vfsStream::setQuota(1);
        $file = vfsStream::url('root/definitions.cache');

        $cache = new FileContainerCache($file);

        try {
            $this->expectException(CacheException::class);
            $cache->set('key1', ['a' => str_repeat('x', 100)], 0);
        } finally {
            vfsStream::setQuota(PHP_INT_MAX);
        }
    }

    /**
     * @throws CacheException
     */
    public function test_set_rename_failure_throws(): void
    {
        // Pre-create the destination path as a real directory so the final
        // rename() of the written temp file can never succeed.
        mkdir($this->file, 0777, true);

        $cache = new FileContainerCache($this->file);

        $this->expectException(CacheException::class);
        $cache->set('key1', ['a' => 1], 0);
    }

    /**
     * @throws ReflectionException
     */
    public function test_removeFile_tolerates_a_concurrently_deleted_file(): void
    {
        // The file never existed; removeFile() must not throw for a file
        // that is already gone by the time it runs (e.g. a concurrent
        // delete() from another process for the same cache key).
        $cache = new FileContainerCache($this->file);

        $ref = new ReflectionClass($cache);
        $method = $ref->getMethod('removeFile');
        $method->invoke($cache);

        $this->assertFileDoesNotExist($this->file);
    }
}
