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

        # Access the service
        $app->get(LazyService::class);

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(LazyServiceProvider::class, $providers);
    }

    /**
     * @throws Throwable
     */
    public function test_eagerServicePreloading_resolvesDuringRegister(): void
    {
        $app = new Application();
        $provider = new LazyServiceProvider();

        # Force eager loading
        $provider->defer = false;

        LazyService::$instantiations = 0;
        $app->registerProvider($provider);
        $app->boot();

        $this->assertSame(1, LazyService::$instantiations, 'An eager provider must resolve its service once, during register().');

        # Accessing the already-shared service must not create a second instance
        $app->get(LazyService::class);

        $this->assertSame(1, LazyService::$instantiations, 'A shared service already resolved during register() must not be re-instantiated.');
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

        LazyService::$instantiations = 0;

        # Access the service
        $lazyService = $app->get(LazyService::class);
        $this->assertInstanceOf(LazyService::class, $lazyService);
        $this->assertSame(1, LazyService::$instantiations, 'A deferred service must not be resolved before its first access.');
    }

}

# dummy classes
class LazyService
{
    public static int $instantiations = 0;

    public function __construct()
    {
        self::$instantiations++;
    }

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
