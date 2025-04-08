<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

#[CoversNothing]
class LazyLoadingApplicationIntegrationTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_lazyServiceDeferredLoading(): void
    {
        $app = new Application();
        $app->registerProvider(new LazyServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Verify provider is not registered yet (before calling get())
        $providers = $app->getProviders();
        $this->assertArrayNotHasKey(LazyServiceProvider::class, $providers);

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertEmpty($cache);

        # Access the service
        $app->get(LazyService::class);

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(LazyServiceProvider::class, $providers);

        # Verify cache is populated
        $cache = $app->getResolvedServicesCache();
        $this->assertNotEmpty($cache);
        $this->assertArrayHasKey(LazyService::class, $cache);
    }

    /**
     * @throws Throwable
     */
    public function test_eagerServicePreloading_wrapsServiceWithCircularDependencyResolver(): void
    {
        $app = new Application();
        $provider = new LazyServiceProvider();

        # Force eager loading
        $provider->defer = false;

        $app->registerProvider($provider);
        $app->boot();

        # Verify cache is populated before accessing the service
        $cache = $app->getResolvedServicesCache();
        $this->assertArrayHasKey(LazyService::class, $cache, 'LazyService should now be in cache after first access');

        # Access the service
        $app->get(LazyService::class);

        # Verify cache is still populated
        $cache = $app->getResolvedServicesCache();
        $this->assertArrayHasKey(LazyService::class, $cache, 'Expected LazyService to be preloaded in cache');
    }

    /**
     * @throws ContainerExceptionInterface| NotFoundExceptionInterface|Throwable
     */
    public function test_lazyServiceDeferredLoading_returnsPlainServiceInstance(): void
    {
        $app = new Application();
        $provider = new LazyServiceProvider();
        $provider->defer = true; // Enable lazy loading
        $app->registerProvider($provider);
        $app->boot();

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertArrayNotHasKey(LazyService::class, $cache, 'LazyService should now be in cache after first access');

        # Access the service
        $lazyService = $app->get(LazyService::class);
        $this->assertInstanceOf(LazyService::class, $lazyService);

        # Verify cache is populated
        $cache = $app->getResolvedServicesCache();
        $this->assertArrayHasKey(LazyService::class, $cache, 'LazyService should now be in cache after first access');
    }

}

# dummy classes
class LazyService
{
    public function process(): string
    {
        return "LazyService has been loaded and processed!";
    }
}

class LazyServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [LazyService::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        $app->bind(LazyService::class, function () {
            return new LazyService();
        }, true);

        // Immediately resolve and store in cache when NOT deferred
        if (!$this->defer) {
            $app->get(LazyService::class);
        }
    }

    public function provides(): array
    {
        return $this->providedServices;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
