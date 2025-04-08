<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversNothing]
class ServiceCachingApplicationIntegrationTest extends TestCase
{
    private string $cacheFile;

    protected function setUp(): void
    {
        putenv('CONTAINER_CACHE=true');
        $this->cacheFile = __DIR__ . '/test_cache/custom.services.cache';

        if (file_exists($this->cacheFile)) {
            unlink($this->cacheFile);
        }
        $cacheDir = dirname($this->cacheFile);
        if (is_dir($cacheDir)) {
            rmdir($cacheDir);
        }
    }

    protected function tearDown(): void
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
    public function test_expensiveServiceCaching(): void
    {
        $app = new Application();
        $app->registerProvider(new ExpensiveServiceProvider());
        $app->setCachePath($this->cacheFile);
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertEmpty($cache);

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayNotHasKey(ExpensiveServiceProvider::class, $providers);

        ob_start();

        if (file_exists($app->getCachePath())) {
            echo "Loading services from cache...\n";
            try {
                $app->loadResolvedServicesFromFile($app->getCachePath());
            } catch (RuntimeException $e) {
                echo "Failed to load service cache: " . $e->getMessage() . "\n";
            }
        } else {
            echo "No cache found. Bootstrapping application...\n";
        }

        $service = $app->get(ExpensiveService::class);

        // Check if correct provider is registered (after using get())
        $providers = $app->getProviders();
        $this->assertArrayHasKey(ExpensiveServiceProvider::class, $providers);

        # cached services now exist
        $cache = $app->getResolvedServicesCache();
        $this->assertNotEmpty($cache);
        $this->assertArrayHasKey(ExpensiveService::class, $cache);

        echo $service->process() . "\n";

        if (!file_exists($app->getCachePath())) {
            try {
                $app->saveResolvedServicesToFile($app->getCachePath());
                echo "Services cached to " . $app->getCachePath() . "\n";
            } catch (RuntimeException $e) {
                echo "Failed to save service cache: " . $e->getMessage() . "\n";
            }
        }

        // Verify the cache file content is as expected
        $cacheContent = file_get_contents($app->getCachePath());
        echo "Cache file content:\n" . $cacheContent . "\n";

        $output = ob_get_clean();

        // Basic output assertions
        $this->assertStringContainsString("No cache found. Bootstrapping application...", $output);
        $this->assertStringContainsString("ExpensiveService: Instance created.", $output);
        $this->assertStringContainsString("ExpensiveService has processed the data!", $output);
        $this->assertStringContainsString("Services cached to " . $this->cacheFile, $output);
        $this->assertStringContainsString("Cache file content:", $output);
        $this->assertFileExists($this->cacheFile);
        $this->assertNotEmpty(file_get_contents($this->cacheFile));

        // Verify that the cache file content contains the expected key.
        $cacheData = file_get_contents($app->getCachePath());
        $cacheArray = unserialize($cacheData, ['allowed_classes' => true]);

        $this->assertIsArray($cacheArray);
        $this->assertArrayHasKey(ExpensiveService::class, $cacheArray);
    }

    /**
     * @throws Throwable
     */
    public function test_expensiveServiceLoadingFromExistingCache(): void
    {
        // Boot first instance and save cache.
        $app1 = new Application();
        $app1->registerProvider(new ExpensiveServiceProvider());
        $app1->setCachePath($this->cacheFile);
        $app1->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app1->isBooted());

        # Verify no cached services yet
        $cache = $app1->getResolvedServicesCache();
        $this->assertEmpty($cache);

        // Check if correct provider is registered
        $providers = $app1->getProviders();
        $this->assertArrayNotHasKey(ExpensiveServiceProvider::class, $providers);

        ob_start();
        $app1->boot();
        $service1 = $app1->get(ExpensiveService::class);

        // Check if correct provider is registered (after using get())
        $providers = $app1->getProviders();
        $this->assertArrayHasKey(ExpensiveServiceProvider::class, $providers);

        echo $service1->process() . "\n";

        if (!file_exists($app1->getCachePath())) {
            $app1->saveResolvedServicesToFile($app1->getCachePath());
            echo "Services cached to " . $app1->getCachePath() . "\n";
        }
        ob_end_clean();

        // Boot second instance using existing cache.
        $app2 = new Application();
        $app2->registerProvider(new ExpensiveServiceProvider());
        $app2->setCachePath($this->cacheFile);

        ob_start();
        if (file_exists($app2->getCachePath())) {
            echo "Loading services from cache...\n";
            try {
                $app2->loadResolvedServicesFromFile($app2->getCachePath());
            } catch (RuntimeException $e) {
                echo "Failed to load service cache: " . $e->getMessage() . "\n";
            }
        } else {
            echo "No cache found. Bootstrapping application...\n";
        }
        $app2->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app2->isBooted());

        # Verify cached services exist
        $cache2 = $app2->getResolvedServicesCache();
        $this->assertNotEmpty($cache2);
        $this->assertArrayHasKey(ExpensiveService::class, $cache2);

        $service2 = $app2->get(ExpensiveService::class);

        // Check if correct provider is registered
        $providers = $app2->getProviders();
        $this->assertArrayHasKey(ExpensiveServiceProvider::class, $providers);

        echo $service2->process() . "\n";
        $output = ob_get_clean();

        # Verify the output
        $this->assertStringContainsString("Loading services from cache...", $output);
        $this->assertEquals(1, substr_count($output, "ExpensiveService: Instance created."), "Service should be instantiated only once.");
        $this->assertStringContainsString("ExpensiveService has processed the data!", $output);
    }
}

# Dummy classes
class ExpensiveService
{
    public function __construct()
    {
        echo "ExpensiveService: Instance created.\n";
    }

    public function process(): string
    {
        return "ExpensiveService has processed the data!";
    }
}

class ExpensiveServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [ExpensiveService::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        $app->bind(ExpensiveService::class, fn () => new ExpensiveService(), true);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
