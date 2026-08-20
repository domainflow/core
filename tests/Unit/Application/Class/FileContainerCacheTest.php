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
    public function test_set_creates_directory_with_restrictive_permissions(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('POSIX permissions are not applicable on Windows.');
        }

        $previousUmask = umask(0);
        try {
            $cache = new FileContainerCache($this->file);
            $cache->set('key1', ['a' => 1], 0);
        } finally {
            umask($previousUmask);
        }

        $permissions = fileperms(dirname($this->file)) & 0777;
        $this->assertSame(0755, $permissions);
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

    public function test_delete_missing_key_preserves_an_existing_store(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('existing', ['a' => 1], 0);

        $this->assertTrue($cache->delete('missing'));
        $this->assertSame(['a' => 1], $cache->get('existing'));
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

    public function test_set_lock_acquisition_failure_throws(): void
    {
        $scheme = 'domainflowlockfail';
        $this->assertTrue(stream_wrapper_register($scheme, LockFailureStreamWrapper::class));

        try {
            $cache = new FileContainerCache($scheme . '://cache/definitions.cache');

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Failed to acquire cache lock');
            $cache->set('key1', ['a' => 1], 0);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_set_lock_open_failure_throws(): void
    {
        $scheme = 'domainflowlockopenfail';
        $this->assertTrue(stream_wrapper_register($scheme, LockOpenFailureStreamWrapper::class));

        try {
            $cache = new FileContainerCache($scheme . '://cache/definitions.cache');

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Failed to open cache lock');
            $cache->set('key1', ['a' => 1], 0);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    public function test_set_lock_permission_failure_throws(): void
    {
        $scheme = 'domainflowlockpermissionfail';
        $this->assertTrue(stream_wrapper_register($scheme, LockPermissionFailureStreamWrapper::class));

        try {
            $cache = new FileContainerCache($scheme . '://cache/definitions.cache');

            $this->expectException(CacheException::class);
            $this->expectExceptionMessage('Failed to secure cache lock');
            $cache->set('key1', ['a' => 1], 0);
        } finally {
            stream_wrapper_unregister($scheme);
        }
    }

    /**
     * @throws CacheException
     */
    public function test_delete_unlink_failure_throws(): void
    {
        $cache = new FileContainerCache($this->file);
        $cache->set('key1', ['a' => 1], 0);
        $cacheDirectory = dirname($this->file);
        chmod($cacheDirectory, 0555);

        try {
            $cache->delete('key1');
            $this->fail('Deleting the final entry from an unwritable cache directory must fail.');
        } catch (CacheException $exception) {
            $this->assertStringContainsString('Failed to delete cache file', $exception->getMessage());
        } finally {
            chmod($cacheDirectory, 0755);
        }
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

    /**
     * @throws CacheException
     */
    public function test_tracked_resource_unchanged_since_write_is_still_a_hit(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        file_put_contents($resourceFile, 'unchanged');
        touch($resourceFile, time() - 100);

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        $this->assertTrue($cache->has('key1'));
        $this->assertSame(['a' => 1], $cache->get('key1'));
    }

    public function test_legacy_tracked_entry_without_content_hash_is_rebuilt_safely(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir(dirname($this->file), 0755, true);
        file_put_contents($resourceFile, 'unchanged');
        $mtime = (int) filemtime($resourceFile);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => [$resourceFile => $mtime],
                ],
            ],
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_tracked_resource_modified_after_write_is_treated_as_a_miss(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        file_put_contents($resourceFile, 'original');
        touch($resourceFile, time() - 100);

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        touch($resourceFile, time() + 100);

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    public function test_tracked_resource_changed_with_same_mtime_is_treated_as_a_miss(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        $recordedMtime = time() - 100;
        file_put_contents($resourceFile, 'original');
        touch($resourceFile, $recordedMtime);

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        file_put_contents($resourceFile, 'changed content');
        touch($resourceFile, $recordedMtime);
        clearstatcache(true, $resourceFile);

        $this->assertFalse($cache->has('key1'));
    }

    public function test_tracked_resource_changed_to_an_older_mtime_is_treated_as_a_miss(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        $recordedMtime = time() - 100;
        file_put_contents($resourceFile, 'original');
        touch($resourceFile, $recordedMtime);

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        file_put_contents($resourceFile, 'restored backup');
        touch($resourceFile, $recordedMtime - 100);
        clearstatcache(true, $resourceFile);

        $this->assertFalse($cache->has('key1'));
    }

    public function test_parallel_writers_do_not_lose_independent_cache_entries(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('This regression test requires process support.');
        }

        $cache = new FileContainerCache($this->file);
        $cache->set('seed', str_repeat('x', 2_000_000), 0);
        $barrier = $this->tempDir . DIRECTORY_SEPARATOR . 'start-workers';
        $autoload = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $worker = <<<'PHP'
            require $argv[1];
            while (!is_file($argv[3])) {
                usleep(1000);
            }
            (new \DomainFlow\Application\Class\FileContainerCache($argv[2]))->set($argv[4], $argv[4], 0);
            PHP;
        $processes = [];

        for ($i = 0; $i < 8; ++$i) {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $worker, $autoload, $this->file, $barrier, 'worker-' . $i],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            $this->assertIsResource($process);
            fclose($pipes[0]);
            $processes[] = [$process, $pipes];
        }

        touch($barrier);

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stdout . $stderr);
        }

        for ($i = 0; $i < 8; ++$i) {
            $this->assertTrue($cache->has('worker-' . $i), 'A concurrent cache write was lost.');
        }
    }

    public function test_parallel_set_and_delete_mutations_preserve_every_completed_operation(): void
    {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('This regression test requires process support.');
        }

        $cache = new FileContainerCache($this->file);
        $cache->set('seed', str_repeat('x', 2_000_000), 0);
        for ($i = 0; $i < 4; ++$i) {
            $cache->set('delete-' . $i, 'present', 0);
        }

        $barrier = $this->tempDir . DIRECTORY_SEPARATOR . 'start-mutation-workers';
        $autoload = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
        $worker = <<<'PHP'
            require $argv[1];
            while (!is_file($argv[3])) {
                usleep(1000);
            }
            $cache = new \DomainFlow\Application\Class\FileContainerCache($argv[2]);
            if ($argv[4] === 'set') {
                $cache->set($argv[5], $argv[5], 0);
            } else {
                $cache->delete($argv[5]);
            }
            PHP;
        $processes = [];

        for ($i = 0; $i < 4; ++$i) {
            foreach ([['set', 'set-' . $i], ['delete', 'delete-' . $i]] as [$operation, $key]) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, '-r', $worker, $autoload, $this->file, $barrier, $operation, $key],
                    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes
                );
                $this->assertIsResource($process);
                fclose($pipes[0]);
                $processes[] = [$process, $pipes];
            }
        }

        touch($barrier);

        foreach ($processes as [$process, $pipes]) {
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $stdout . $stderr);
        }

        for ($i = 0; $i < 4; ++$i) {
            $this->assertTrue($cache->has('set-' . $i), 'A concurrent set operation was lost.');
            $this->assertFalse($cache->has('delete-' . $i), 'A concurrent delete operation was lost.');
        }
    }

    /**
     * @throws CacheException
     */
    public function test_tracked_resource_removed_after_write_is_treated_as_a_miss(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        file_put_contents($resourceFile, 'original');

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        unlink($resourceFile);

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_untracked_key_is_unaffected_by_a_changed_file_tracked_for_another_key(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        mkdir($this->tempDir, 0755, true);
        file_put_contents($resourceFile, 'original');
        touch($resourceFile, time() - 100);

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);
        $cache->set('key2', ['b' => 2], 0);

        touch($resourceFile, time() + 100);

        $this->assertFalse($cache->has('key1'), 'The entry a resource was tracked for must invalidate.');
        $this->assertTrue($cache->has('key2'), 'An entry with no tracked resource must be unaffected.');
        $this->assertSame(['b' => 2], $cache->get('key2'));
    }

    /**
     * @throws CacheException
     */
    public function test_trackResource_for_a_file_that_never_existed_does_not_prevent_a_hit(): void
    {
        $resourceFile = $this->tempDir . DIRECTORY_SEPARATOR . 'never-existed.yaml';

        $cache = new FileContainerCache($this->file);
        $cache->trackResource('key1', $resourceFile);
        $cache->set('key1', ['a' => 1], 0);

        $this->assertTrue($cache->has('key1'), 'A resource that never existed at write time is not tracked, so it cannot cause a false miss.');
    }

    /**
     * @throws CacheException
     */
    public function test_entry_with_tampered_resources_shape_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => ['not-an-int-mtime'],
                ],
            ],
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertFalse($cache->has('key1'));
        $this->assertNull($cache->get('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_entry_with_non_array_resources_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => 'not-an-array',
                ],
            ],
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertFalse($cache->has('key1'));
    }

    /**
     * @throws CacheException
     */
    public function test_entry_with_unexpected_field_alongside_resources_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => [],
                    'unexpected' => 'field',
                ],
            ],
        ]));

        $cache = new FileContainerCache($this->file);

        $this->assertFalse($cache->has('key1'));
    }

    public function test_entry_with_mismatched_resource_hash_keys_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => ['/tmp/resource' => 123],
                    'resourceHashes' => [],
                ],
            ],
        ]));

        $this->assertFalse((new FileContainerCache($this->file))->has('key1'));
    }

    public function test_entry_with_invalid_resource_hash_is_treated_as_a_miss(): void
    {
        mkdir(dirname($this->file), 0777, true);
        file_put_contents($this->file, json_encode([
            'version' => 1,
            'entries' => [
                'key1' => [
                    'value' => ['a' => 1],
                    'expiresAt' => null,
                    'resources' => ['/tmp/resource' => 123],
                    'resourceHashes' => ['/tmp/resource' => 'not-a-sha256-hash'],
                ],
            ],
        ]));

        $this->assertFalse((new FileContainerCache($this->file))->has('key1'));
    }
}

final class LockFailureStreamWrapper
{
    /** @var resource|null */
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return false;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return true;
    }

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0040777];
    }
}

final class LockOpenFailureStreamWrapper
{
    /** @var resource|null */
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        return false;
    }

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0040777];
    }
}

final class LockPermissionFailureStreamWrapper
{
    /** @var resource|null */
    public mixed $context;

    public function stream_open(
        string $path,
        string $mode,
        int $options,
        ?string &$openedPath
    ): bool {
        return true;
    }

    public function stream_lock(int $operation): bool
    {
        return true;
    }

    public function stream_metadata(string $path, int $option, mixed $value): bool
    {
        return false;
    }

    /** @return array{mode: int} */
    public function url_stat(string $path, int $flags): array
    {
        return ['mode' => 0040777];
    }
}
