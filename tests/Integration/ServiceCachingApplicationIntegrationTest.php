<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Class\FileContainerCache;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Exercises the safe, cross-process declarative binding cache: Core supplies
 * a filesystem ContainerCacheInterface adapter (FileContainerCache); the
 * validated, versioned cache content and its cold/warm hydration semantics
 * are owned entirely by domainflow/container. No resolved object is ever
 * persisted, and a cache hit never bypasses the boot() lifecycle.
 */
#[CoversNothing]
class ServiceCachingApplicationIntegrationTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        $this->cacheFile = __DIR__ . '/test_cache/definitions.cache';
        CachedService::$instantiations = 0;
        $this->removeCacheArtifacts();
    }

    protected function tearDown(): void
    {
        $this->removeCacheArtifacts();
    }

    private function removeCacheArtifacts(): void
    {
        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
        $cacheDir = dirname($this->cacheFile);
        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }
    }

    /**
     * @throws Throwable
     */
    public function test_bindings_persist_across_application_instances(): void
    {
        $app1 = new Application(__DIR__);
        $app1->setExternalCache(new FileContainerCache($this->cacheFile));
        $app1->bind(CachedService::class, CachedService::class, true);
        $app1->boot();

        $this->assertFileExists($this->cacheFile, 'Binding a class-string cacheable service must persist the cache file.');
        $cacheContent = (string) file_get_contents($this->cacheFile);
        $this->assertStringNotContainsString('O:', $cacheContent, 'Cache file must never contain a PHP serialized object.');

        // A fresh Application instance, without ever calling bind() itself,
        // restores the binding purely from the validated declarative cache.
        $app2 = new Application(__DIR__);
        $app2->setExternalCache(new FileContainerCache($this->cacheFile));

        $this->assertTrue($app2->has(CachedService::class), 'A warm Application should restore the binding from cache.');
    }

    /**
     * @throws Throwable
     */
    public function test_cache_hit_still_runs_the_full_boot_lifecycle_and_resolves_fresh_instances(): void
    {
        $app1 = new Application(__DIR__);
        $app1->setExternalCache(new FileContainerCache($this->cacheFile));
        $app1->bind(CachedService::class, CachedService::class, false);
        $service1 = $app1->get(CachedService::class);

        $this->assertSame(1, CachedService::$instantiations);

        $app2 = new Application(__DIR__);
        $app2->setExternalCache(new FileContainerCache($this->cacheFile));

        $bootingRan = false;
        $bootedRan = false;
        $providerRegistered = false;
        $app2->booting(function () use (&$bootingRan) {
            $bootingRan = true;
        });
        $app2->booted(function () use (&$bootedRan) {
            $bootedRan = true;
        });
        $app2->registerProvider(new CountingServiceProvider(function () use (&$providerRegistered) {
            $providerRegistered = true;
        }));

        $app2->boot();

        $this->assertTrue($bootingRan, 'A cache hit must not skip the booting callback stage.');
        $this->assertTrue($bootedRan, 'A cache hit must not skip the booted callback stage.');
        $this->assertTrue($providerRegistered, 'A cache hit must not skip provider registration.');
        $this->assertTrue($app2->isBooted());

        $this->assertTrue($app2->has(CachedService::class), 'The restored binding should be usable without re-declaring it.');

        $service2 = $app2->get(CachedService::class);

        $this->assertSame(2, CachedService::$instantiations, 'A warm-cache resolution must build a fresh instance, never deserialize one.');
        $this->assertNotSame($service1, $service2);
    }

    /**
     * @throws Throwable
     */
    public function test_corrupt_cache_file_is_ignored_not_trusted(): void
    {
        mkdir(dirname($this->cacheFile), 0777, true);
        file_put_contents($this->cacheFile, '{"tampered": "not a valid definitions payload"}');

        $app = new Application(__DIR__);
        $app->setExternalCache(new FileContainerCache($this->cacheFile));

        $this->assertFalse($app->has(CachedService::class), 'A corrupt or tampered cache file must never be trusted.');

        // The application still boots and resolves normally after an
        // explicit bind(), proving the corrupt cache did not hide or break
        // valid application state.
        $app->bind(CachedService::class, CachedService::class, false);
        $app->boot();

        $this->assertTrue($app->isBooted());
        $this->assertInstanceOf(CachedService::class, $app->get(CachedService::class));
    }

    /**
     * @throws Throwable
     */
    public function test_cache_populated_from_a_service_definition_file_stays_a_hit_while_the_file_is_unchanged(): void
    {
        $definitionsFile = $this->tempDefinitionsFile();
        file_put_contents($definitionsFile, '<?php return ["TestService" => ["concrete" => "stdClass", "shared" => true]];');

        $app1 = new Application(__DIR__);
        $app1->setExternalCache(new FileContainerCache($this->cacheFile));
        $app1->loadServiceDefinitions($definitionsFile);

        $app2 = new Application(__DIR__);
        $app2->setExternalCache(new FileContainerCache($this->cacheFile));

        $this->assertTrue(
            $app2->has('TestService'),
            'A warm Application must restore bindings from a cache whose tracked service-definition file has not changed.'
        );

        unlink($definitionsFile);
    }

    /**
     * @throws Throwable
     */
    public function test_cache_populated_from_a_service_definition_file_self_invalidates_once_the_file_changes(): void
    {
        $definitionsFile = $this->tempDefinitionsFile();
        file_put_contents($definitionsFile, '<?php return ["TestService" => ["concrete" => "stdClass", "shared" => true]];');

        $app1 = new Application(__DIR__);
        $app1->setExternalCache(new FileContainerCache($this->cacheFile));
        $app1->loadServiceDefinitions($definitionsFile);

        touch($definitionsFile, time() + 100);

        $app2 = new Application(__DIR__);
        $app2->setExternalCache(new FileContainerCache($this->cacheFile));

        $this->assertFalse(
            $app2->has('TestService'),
            'A changed service-definition file must self-invalidate the cache instead of silently serving a stale binding.'
        );

        unlink($definitionsFile);
    }

    private function tempDefinitionsFile(): string
    {
        $dir = dirname($this->cacheFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        return $dir . DIRECTORY_SEPARATOR . 'services.php';
    }
}

# Dummy classes
class CachedService
{
    public static int $instantiations = 0;

    public function __construct()
    {
        self::$instantiations++;
    }
}

class CountingServiceProvider extends \DomainFlow\Service\AbstractServiceProvider
{
    /**
     * @param callable(): void $onRegister
     */
    public function __construct(
        private readonly mixed $onRegister
    ) {
    }

    public function register(
        Application $app
    ): void {
        ($this->onRegister)();
    }

    public function isDeferred(): bool
    {
        return false;
    }
}
