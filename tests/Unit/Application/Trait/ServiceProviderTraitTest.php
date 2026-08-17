<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Traits\ServiceProviderTrait;
use DomainFlow\Service\AbstractServiceProvider;
use DomainFlow\Service\ServiceProviderInterface;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use Throwable;

#[CoversClass(Application::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(EventDispatcherServiceProvider::class)]
#[CoversClass(SystemEventStore::class)]
final class ServiceProviderTraitTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_registerProviderRegistersAndFiresEvent(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service1']);
        $provider->defer = true;

        $container->registerProvider($provider);
        $class = get_class($provider);

        $this->assertArrayHasKey('service1', $container->getDeferredServices());
        $this->assertArrayNotHasKey($class, $container->getProviders());

        $value = $container->get('service1');

        $this->assertArrayHasKey($class, $container->getProviders());
        $this->assertEquals("default_service1", $value);

        $found = false;
        foreach ($container->events as $event) {
            if ($event['event'] === 'service_provider.deferred.loaded' && $event['args'][0] === 'service1') {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Deferred provider loaded event not fired.");

    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderRegistersAndFiresEvent_nonDefer(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service1']);

        $container->registerProvider($provider);
        $class = get_class($provider);

        $this->assertArrayHasKey($class, $container->getProviders());

        $value = $container->get('service1');
        $this->assertEquals("default_service1", $value);

        $found = false;
        foreach ($container->events as $event) {
            if ($event['event'] === 'service_provider.registered' && $event['args'][0] === $class) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Provider registered event not fired.");

        $this->assertArrayHasKey($class, $container->getProviders());
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderDoesNotRegisterTwice(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service1']);

        $container->registerProvider($provider);
        $container->registerProvider($provider);

        $this->assertCount(1, $container->getProviders());
        $this->assertCount(0, $container->getDeferredServices());
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderBootsIfAlreadyBooted(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $container->booted = true;
        $provider = new DummyProvider(['service2']);

        $container->registerProvider($provider);

        $this->assertTrue($provider->bootedCalled);
    }

    /**
     * @throws EventManagerException|Throwable
     */
    public function test_unregisterProviderRemovesProviderAndDeferredServices(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service3', 'service4']);
        $provider->defer = true;
        $class = get_class($provider);
        $container->registerProvider($provider);

        $this->assertArrayHasKey('service3', $container->getDeferredServices());
        $this->assertArrayHasKey('service4', $container->getDeferredServices());

        $container->unregisterProvider($class);

        $this->assertFalse(isset($container->getProviders()[$class]));
        $this->assertFalse(isset($container->getDeferredServices()['service3']));
        $this->assertFalse(isset($container->getDeferredServices()['service4']));

        $found = false;
        foreach ($container->events as $event) {
            if ($event['event'] === 'service_provider.unregistered'
                && $event['args'][0] === $class) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Provider unregistered event not fired.");
    }

    /**
     * @throws EventManagerException|Throwable
     */
    public function test_unregisterProviderRemovesProviderAndDeferredServicesCompletely(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service9', 'service10']);
        $provider->defer = false;
        $providerClass = get_class($provider);

        $container->registerProvider($provider);

        $this->assertArrayHasKey($providerClass, $container->getProviders());

        $container->unregisterProvider($providerClass);

        $this->assertArrayNotHasKey($providerClass, $container->getProviders());
    }

    /**
     * @throws Throwable
     */
    public function test_getProvidersReturnsProviders(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = $this->createStub(DummyProvider::class);
        $container->registerProvider($provider);
        $providers = $container->getProviders();

        $this->assertArrayHasKey(get_class($provider), $providers);
    }

    /**
     * @throws Throwable
     */
    public function test_getProviders(): void
    {
        $container = new Application();
        $provider = $this->createStub(DummyProvider::class);
        $container->registerProvider($provider);

        $providers = $container->getProviders();
        $this->assertArrayHasKey(get_class($provider), $providers);
    }

    /**
     * @throws Throwable
     */
    public function test_loadDeferredProvidersLoadsProviders(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $dummyProviderClass = DummyProvider::class;
        $ref = new ReflectionClass($container);
        $prop = $ref->getProperty('deferredServices');
        $prop->setValue($container, ['service6' => $dummyProviderClass]);

        $container->loadDeferredProviders();

        $this->assertFalse(isset($container->getDeferredServices()['service6']));

        $found = false;
        foreach ($container->events as $event) {
            if (
                $event['event'] === 'service_provider.deferred.loaded'
                && $event['args'][0] === 'service6'
                && $event['args'][1] === $dummyProviderClass
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Deferred provider loaded event not fired.");
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|Throwable
     */
    public function test_applicationGetResolvesDeferredProvider(): void
    {
        $app = new Application();
        $app->registerProvider(new DummyProvider(
            ['service7'],
            true
        ));
        $app->boot();
        $service = $app->get(ConsoleService::class);

        $this->assertInstanceOf(ConsoleService::class, $service);
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|Throwable
     */
    public function test_getPopulatesResolvedServicesCache(): void
    {
        $app = new Application();
        $app->boot();

        $this->assertArrayNotHasKey(ConsoleService::class, $app->getResolvedServicesCache());

        $service = $app->get(ConsoleService::class);

        $cache = $app->getResolvedServicesCache();
        $this->assertArrayHasKey(ConsoleService::class, $cache);
        $this->assertSame($service, $cache[ConsoleService::class]);
    }

    /**
     * @throws Throwable
     */
    public function test_hasProvider(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service8']);
        $class = get_class($provider);
        $this->assertFalse($container->hasProvider($class));
        $container->registerProvider($provider);
        $this->assertTrue($container->hasProvider($class));
    }
}

# Dummy classes
class DummyContainerServiceProvider
{
    /** @var array<string, string> */
    public array $services = [];

    public function get(
        string $id
    ): mixed {
        return $this->services[$id] ?? "default_$id";
    }

    public function has(
        string $id
    ): bool {
        return isset($this->services[$id]);
    }

    public function cacheResolvedService(
        string $abstract,
        mixed $instance
    ): void {
    }
}

class DummyServiceProviderContainerServiceProvider extends DummyContainerServiceProvider
{
    use ServiceProviderTrait;

    public bool $booted = false;
    public array $events = [];

    protected function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->events[] = ['event' => $event, 'args' => $args];
    }

    public function getProviders(): array
    {
        return $this->serviceProviders;
    }

    public function &getDeferredServices(): array
    {
        return $this->deferredServices;
    }

    public function registerService(
        string $service,
        ServiceProviderInterface $provider
    ): void {
        $this->services[$service] = "default_$service";
    }
}

class DummyProvider implements ServiceProviderInterface
{
    public bool $registered = false;
    public bool $bootedCalled = false;
    private array $provides;
    public bool $defer = false;

    public function __construct(
        array $provides = [],
        bool $defer = false
    ) {
        $this->provides = $provides;
        $this->defer = $defer;
    }

    public function register(
        $app
    ): void {
        $this->registered = true;

        foreach ($this->provides as $service) {
            if (method_exists($app, 'bind')) {
                $app->bind($service, fn () => "default_$service");
            } else {
                $app->services[$service] = "default_$service";
            }
        }
    }

    public function boot(
        $app
    ): void {
        $this->bootedCalled = true;
    }

    public function provides(): array
    {
        return $this->provides;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}

class ConsoleService
{
    public function sayHello(): string
    {
        return 'Hello from ConsoleService!';
    }
}

class ConsoleServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [ConsoleService::class];
    public bool $defer = true;
    public function register(
        Application $app
    ): void {
        $app->bind(ConsoleService::class, fn () => new ConsoleService(), true);
    }

    public function boot(
        Application $app
    ): void {
        echo "ConsoleServiceProvider booted.\n";
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
