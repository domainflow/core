<?php

declare(strict_types=1);

namespace DomainFlow\Application\Class;

use DomainFlow\Application\Exception\CacheException;
use DomainFlow\Container\Cache\ContainerCacheInterface;
use JsonException;
use RuntimeException;

/**
 * Class FileContainerCache
 *
 * Filesystem-backed ContainerCacheInterface adapter for
 * domainflow/container's declarative definitions cache.
 *
 * Only ever persists the versioned, JSON-encodable payloads
 * domainflow/container writes through this interface (validated
 * class-string bindings, shared flags, and aliases) — never a PHP
 * serialize()/unserialize() of arbitrary values, so a cache file can never
 * instantiate an object on load. Malformed content, an unexpected format
 * version, or an unreadable file is always treated as a cache miss rather
 * than trusted: a corrupted or tampered cache file costs a cold rebuild,
 * never a wrong resolution.
 *
 * A caller may opt a key into resource-tracked invalidation via
 * trackResource(): the next set() for that key records the tracked file's
 * mtime and content hash alongside the value. A later get()/has() treats the
 * entry as a miss once either fingerprint component differs. A key nothing
 * was tracked for is entirely unaffected and behaves exactly as before.
 *
 * Mutations hold a stable sidecar-file lock across the complete
 * read-modify-write cycle, then atomically rename the new payload into place.
 * Concurrent set/delete operations therefore cannot overwrite each other's
 * completed changes.
 */
final class FileContainerCache implements ContainerCacheInterface
{
    private const int FORMAT_VERSION = 1;

    /**
     * @var array<string, array<string, true>>
     */
    private array $trackedResources = [];

    public function __construct(
        private readonly string $filePath
    ) {
    }

    /**
     * Track a source file for a cache key. The next set() for that key
     * records the file's current mtime and content hash alongside the value;
     * a later get()/has() treats the entry as a miss once either fingerprint
     * changes or the file is gone.
     *
     * @param string $key
     * @param string $resourceFile
     * @return void
     */
    public function trackResource(
        string $key,
        string $resourceFile
    ): void {
        $this->trackedResources[$key][$resourceFile] = true;
    }

    /**
     * @param string $key
     * @return mixed
     */
    public function get(
        string $key
    ): mixed {
        $entry = $this->readEntry($key);

        return $entry['found'] ? $entry['value'] : null;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(
        string $key
    ): bool {
        return $this->readEntry($key)['found'];
    }

    /**
     * @param string $key
     * @param mixed $value
     * @param int $ttl A TTL of zero means the value never expires.
     * @throws CacheException
     * @return bool
     */
    public function set(
        string $key,
        mixed $value,
        int $ttl = 3600
    ): bool {
        $this->withExclusiveLock(function () use ($key, $value, $ttl): void {
            $store = $this->readStore();
            $entry = [
                'value' => $value,
                'expiresAt' => $ttl > 0 ? time() + $ttl : null,
            ];
            $resourceFingerprints = $this->currentResourceFingerprints($key);
            if ($resourceFingerprints['mtimes'] !== []) {
                $entry['resources'] = $resourceFingerprints['mtimes'];
                $entry['resourceHashes'] = $resourceFingerprints['hashes'];
            }
            $store[$key] = $entry;
            $this->writeStore($store);
        });

        return true;
    }

    /**
     * @param string $key
     * @return array{mtimes: array<string, int>, hashes: array<string, string>}
     */
    private function currentResourceFingerprints(
        string $key
    ): array {
        $mtimes = [];
        $hashes = [];
        foreach (array_keys($this->trackedResources[$key] ?? []) as $file) {
            clearstatcache(true, $file);
            $mtime = @filemtime($file);
            $hash = @hash_file('sha256', $file);
            if ($mtime !== false && $hash !== false) {
                $mtimes[$file] = $mtime;
                $hashes[$file] = $hash;
            }
        }

        return ['mtimes' => $mtimes, 'hashes' => $hashes];
    }

    /**
     * @param string $key
     * @throws CacheException
     * @return bool
     */
    public function delete(
        string $key
    ): bool {
        if (!is_file($this->filePath)) {
            return true;
        }

        $this->withExclusiveLock(function () use ($key): void {
            $store = $this->readStore();
            if (!array_key_exists($key, $store)) {
                return;
            }

            unset($store[$key]);

            if ($store === []) {
                $this->removeFile();
            } else {
                $this->writeStore($store);
            }
        });

        return true;
    }

    /**
     * @param string $key
     * @return array{found: bool, value: mixed}
     */
    private function readEntry(
        string $key
    ): array {
        $store = $this->readStore();
        if (!array_key_exists($key, $store)) {
            return ['found' => false, 'value' => null];
        }

        $entry = $store[$key];
        if ($entry['expiresAt'] !== null && $entry['expiresAt'] <= time()) {
            return ['found' => false, 'value' => null];
        }

        if (isset($entry['resources'])
            && !$this->resourcesAreFresh($entry['resources'], $entry['resourceHashes'] ?? [])
        ) {
            return ['found' => false, 'value' => null];
        }

        return ['found' => true, 'value' => $entry['value']];
    }

    /**
     * @param array<string, int> $resources
     * @param array<string, string> $resourceHashes
     */
    private function resourcesAreFresh(
        array $resources,
        array $resourceHashes
    ): bool {
        // Cache files written by versions that only recorded mtimes cannot
        // uphold same-mtime content invalidation. Rebuild those tracked
        // entries once instead of trusting an incomplete fingerprint.
        if ($resources !== [] && $resourceHashes === []) {
            return false;
        }

        foreach ($resources as $file => $recordedMtime) {
            clearstatcache(true, $file);
            $currentMtime = @filemtime($file);
            if ($currentMtime === false || $currentMtime !== $recordedMtime) {
                return false;
            }

            if (isset($resourceHashes[$file])) {
                $currentHash = @hash_file('sha256', $file);
                if ($currentHash === false || !hash_equals($resourceHashes[$file], $currentHash)) {
                    return false;
                }
            }

        }

        return true;
    }

    /**
     * Read and validate the on-disk store. Any I/O failure, malformed JSON,
     * unexpected shape, or format-version mismatch is treated as an empty
     * store — never trusted, never fatal.
     *
     * @return array<string, array{value: mixed, expiresAt: int|null, resources?: array<string, int>, resourceHashes?: array<string, string>}>
     */
    private function readStore(): array
    {
        if (!is_file($this->filePath)) {
            return [];
        }

        $raw = @file_get_contents($this->filePath);
        if ($raw === false) {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return [];
        }

        if (!$this->isValidStore($decoded)) {
            return [];
        }

        /** @var array{version: int, entries: array<string, array{value: mixed, expiresAt: int|null, resources?: array<string, int>, resourceHashes?: array<string, string>}>} $decoded */
        return $decoded['entries'];
    }

    /**
     * @param mixed $decoded
     */
    private function isValidStore(
        mixed $decoded
    ): bool {
        if (!is_array($decoded)
            || ($decoded['version'] ?? null) !== self::FORMAT_VERSION
            || array_diff(array_keys($decoded), ['version', 'entries']) !== []
            || count($decoded) !== 2
            || !isset($decoded['entries'])
            || !is_array($decoded['entries'])
        ) {
            return false;
        }

        foreach ($decoded['entries'] as $key => $entry) {
            if (!is_string($key) || !$this->isValidEntry($entry)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $entry
     */
    private function isValidEntry(
        mixed $entry
    ): bool {
        if (!is_array($entry)
            || array_diff(array_keys($entry), ['value', 'expiresAt', 'resources', 'resourceHashes']) !== []
            || !array_key_exists('value', $entry)
            || !array_key_exists('expiresAt', $entry)
            || !(is_int($entry['expiresAt']) || $entry['expiresAt'] === null)
        ) {
            return false;
        }

        if (!array_key_exists('resources', $entry)) {
            return count($entry) === 2;
        }

        if (!$this->isValidResources($entry['resources'])) {
            return false;
        }

        if (!array_key_exists('resourceHashes', $entry)) {
            return count($entry) === 3;
        }

        return count($entry) === 4
            && $this->isValidResourceHashes($entry['resourceHashes'], $entry['resources']);
    }

    /**
     * @param mixed $resources
     */
    private function isValidResources(
        mixed $resources
    ): bool {
        if (!is_array($resources)) {
            return false;
        }

        foreach ($resources as $file => $mtime) {
            if (!is_string($file) || $file === '' || !is_int($mtime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param mixed $resourceHashes
     * @param mixed $resources
     * @return bool
     */
    private function isValidResourceHashes(
        mixed $resourceHashes,
        mixed $resources
    ): bool {
        if (!is_array($resourceHashes)
            || !is_array($resources)
            || array_keys($resourceHashes) !== array_keys($resources)
        ) {
            return false;
        }

        foreach ($resourceHashes as $file => $hash) {
            if (!is_string($file) || !is_string($hash) || strlen($hash) !== 64 || !ctype_xdigit($hash)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Execute a complete cache mutation while holding one stable lock.
     *
     * @param callable():void $operation
     * @throws CacheException
     * @return void
     */
    private function withExclusiveLock(
        callable $operation
    ): void {
        $this->ensureCacheDirectoryExists();
        $lockPath = $this->filePath . '.lock';
        $lock = @fopen($lockPath, 'c+b');
        if ($lock === false) {
            throw CacheException::forUnknownError("Failed to open cache lock: $lockPath");
        }

        try {
            if (!@chmod($lockPath, 0600)) {
                throw CacheException::forUnknownError("Failed to secure cache lock permissions: $lockPath");
            }
            if (!flock($lock, LOCK_EX)) {
                throw CacheException::forUnknownError("Failed to acquire cache lock: $lockPath");
            }

            try {
                $operation();
            } finally {
                flock($lock, LOCK_UN);
            }
        } finally {
            fclose($lock);
        }
    }

    /**
     * @throws CacheException
     * @return void
     */
    private function ensureCacheDirectoryExists(): void
    {
        $dir = dirname($this->filePath);
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true) && !is_dir($dir)) {
                throw CacheException::forUnknownError("Failed to create cache directory: $dir");
            }
            chmod($dir, 0755);
        }
    }

    /**
     * @param array<string, array{value: mixed, expiresAt: int|null, resources?: array<string, int>, resourceHashes?: array<string, string>}> $store
     * @throws CacheException
     */
    private function writeStore(
        array $store
    ): void {
        $this->ensureCacheDirectoryExists();

        try {
            $encoded = json_encode([
                'version' => self::FORMAT_VERSION,
                'entries' => $store,
            ], JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw CacheException::forWriteFailure($this->filePath, $exception);
        }

        $tmpFile = $this->filePath . '.' . bin2hex(random_bytes(8)) . '.tmp';
        if (@file_put_contents($tmpFile, $encoded, LOCK_EX) === false) {
            throw CacheException::forWriteFailure($this->filePath);
        }
        chmod($tmpFile, 0600);

        if (!@rename($tmpFile, $this->filePath)) {
            @unlink($tmpFile);
            throw CacheException::forWriteFailure($this->filePath);
        }
    }

    /**
     * @throws CacheException
     */
    private function removeFile(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        if (!@unlink($this->filePath)) {
            throw CacheException::forCacheCleanedError(
                new RuntimeException("Failed to delete cache file: {$this->filePath}")
            );
        }
    }
}
