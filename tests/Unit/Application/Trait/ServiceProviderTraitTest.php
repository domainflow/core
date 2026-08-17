<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\BootstrappingException;
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
use RuntimeException;
use Throwable;

#[CoversClass(Application::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(BootstrappingException::class)]
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
    public function test_eagerProviderRegisteredBeforeBoot_registersAndBootsExactlyOnce(): void
    {
        $app = new Application();
        $provider = new DummyProvider();

        $app->registerProvider($provider);
        $app->boot();

        $this->assertSame(1, $provider->registerCallCount);
        $this->assertSame(1, $provider->bootCallCount);
    }

    /**
     * @throws Throwable
     */
    public function test_eagerProviderRegisteredAfterBoot_registersAndBootsExactlyOnce(): void
    {
        $app = new Application();
        $app->boot();

        $provider = new DummyProvider();
        $app->registerProvider($provider);

        $this->assertSame(1, $provider->registerCallCount);
        $this->assertSame(1, $provider->bootCallCount);
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderCalledTwiceBeforeBoot_registersAndBootsExactlyOnce(): void
    {
        $app = new Application();
        $provider = new DummyProvider();

        $app->registerProvider($provider);
        $app->registerProvider($provider);
        $app->boot();

        $this->assertSame(1, $provider->registerCallCount);
        $this->assertSame(1, $provider->bootCallCount);
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|Throwable
     */
    public function test_deferredProviderLoadedViaGet_registersAndBootsExactlyOnce(): void
    {
        CountingDeferredProvider::$registerCallCount = 0;
        CountingDeferredProvider::$bootCallCount = 0;

        $app = new Application();
        $app->registerProvider(new CountingDeferredProvider());
        $app->boot();

        $app->get(CountingDeferredService::class);
        $app->get(CountingDeferredService::class);

        $this->assertSame(1, CountingDeferredProvider::$registerCallCount);
        $this->assertSame(1, CountingDeferredProvider::$bootCallCount);
    }

    /**
     * @throws Throwable
     */
    public function test_deferredProviderRegistrationFailure_isWrappedAndRetryableOnNextGet(): void
    {
        ThrowingDeferredProvider::$shouldThrow = true;
        ThrowingDeferredProvider::$registerCallCount = 0;
        ThrowingDeferredProvider::$bootCallCount = 0;

        $app = new Application();
        $app->registerProvider(new ThrowingDeferredProvider());
        $app->boot();

        try {
            $app->get(ThrowingDeferredService::class);
            $this->fail('Expected BootstrappingException was not thrown');
        } catch (BootstrappingException $e) {
            $this->assertStringContainsString(ThrowingDeferredService::class, $e->getMessage());
            $this->assertStringContainsString(ThrowingDeferredProvider::class, $e->getMessage());
            $this->assertNotNull($e->getPrevious());
        }

        $this->assertSame(1, ThrowingDeferredProvider::$registerCallCount);
        $this->assertSame(0, ThrowingDeferredProvider::$bootCallCount);

        ThrowingDeferredProvider::$shouldThrow = false;
        $service = $app->get(ThrowingDeferredService::class);

        $this->assertInstanceOf(ThrowingDeferredService::class, $service);
        $this->assertSame(2, ThrowingDeferredProvider::$registerCallCount, 'A failed deferred load is retried on the next get().');
        $this->assertSame(1, ThrowingDeferredProvider::$bootCallCount);
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

    /**
     * @throws Throwable
     */
    public function test_registerProvider_deferredIdentifierCollision_throwsAndPreservesFirstClaim(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $first = new DummyProvider(['shared.service'], true);
        $second = new SecondDummyProvider(['shared.service'], true);

        $container->registerProvider($first);

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage(sprintf(
            'Deferred service identifier [shared.service] is already claimed by [%s] and cannot also be claimed by [%s].',
            get_class($first),
            get_class($second)
        ));

        try {
            $container->registerProvider($second);
        } finally {
            $this->assertSame(
                get_class($first),
                $container->getDeferredServices()['shared.service'],
                'The first claiming provider must remain the owner of the identifier after a rejected collision.'
            );
        }
    }

    /**
     * @throws Throwable
     */
    public function test_registerProvider_sameDeferredProviderClassTwice_isNotACollision(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $provider = new DummyProvider(['service1'], true);

        $container->registerProvider($provider);
        $container->registerProvider($provider);

        $this->assertArrayHasKey('service1', $container->getDeferredServices());
    }

    /**
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|Throwable
     */
    public function test_deferredProviderWithConstructorDependency_retainsRegisteredInstanceOnResolution(): void
    {
        $dependency = new LoggerStub('injected-value');
        $provider = new ConstructorDependentDeferredProvider($dependency);

        $app = new Application();
        $app->registerProvider($provider);
        $app->boot();

        // LoggerContractInterface is not autowirable: it is an interface, so
        // container make() cannot instantiate it without an explicit binding.
        // Resolving it successfully proves the deferred provider (holding the
        // constructor-injected $dependency) actually ran.
        $resolved = $app->get(LoggerContractInterface::class);

        $this->assertSame($dependency, $resolved);
        $this->assertSame('injected-value', $resolved->value);
    }

    /**
     * @throws Throwable
     */
    public function test_deferredProviderRegisteredBeforeBoot_staysUnregisteredAndUnbootedThroughBoot(): void
    {
        $provider = new DummyProvider(['service.deferred.until.get'], true);

        $app = new Application();
        $app->registerProvider($provider);
        $app->boot();

        $this->assertSame(0, $provider->registerCallCount, 'A deferred provider must not register during boot().');
        $this->assertSame(0, $provider->bootCallCount, 'A deferred provider must not boot during boot().');
        $this->assertFalse($app->hasProvider(get_class($provider)));

        $app->get('service.deferred.until.get');

        $this->assertSame(1, $provider->registerCallCount);
        $this->assertSame(1, $provider->bootCallCount);
    }

    /**
     * @throws Throwable
     */
    public function test_unregisterProvider_freesClaimedDeferredIdentifierForReuse(): void
    {
        $container = new DummyServiceProviderContainerServiceProvider();
        $first = new DummyProvider(['reusable.service'], true);
        $second = new DummyProvider(['reusable.service'], true);

        $container->registerProvider($first);
        $container->unregisterProvider(get_class($first));

        $container->registerProvider($second);

        $this->assertSame('reusable.service', array_key_first($container->getDeferredServices()));
        $this->assertSame(get_class($second), $container->getDeferredServices()['reusable.service']);
    }

    /**
     * @throws Throwable
     */
    public function test_resolveDeferredProvider_missingStoredInstance_throwsInvariantViolation(): void
    {
        // registerProvider() always populates deferredServices and
        // deferredProviderInstances together; this simulates the two maps
        // falling out of sync (an internal invariant violation) to prove
        // resolution fails loudly instead of silently instantiating a
        // fresh, dependency-less provider.
        $container = new DummyServiceProviderContainerServiceProvider();
        $ref = new ReflectionClass($container);
        $ref->getProperty('deferredServices')->setValue($container, ['service.orphaned' => DummyProvider::class]);

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage(
            'No registered instance found for deferred provider [' . DummyProvider::class . '] '
            . 'while resolving service [service.orphaned].'
        );

        $container->get('service.orphaned');
    }

    /**
     * @throws Throwable
     */
    public function test_loadDeferredProviders_preWarmsRegisteredDeferredProvider(): void
    {
        $provider = new DummyProvider(['service6'], true);

        $container = new DummyServiceProviderContainerServiceProvider();
        $container->registerProvider($provider);

        $container->loadDeferredProviders();

        $this->assertFalse(isset($container->getDeferredServices()['service6']));
        $this->assertSame(1, $provider->registerCallCount);

        $found = false;
        foreach ($container->events as $event) {
            if (
                $event['event'] === 'service_provider.deferred.loaded'
                && $event['args'][0] === 'service6'
                && $event['args'][1] === get_class($provider)
            ) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Deferred provider loaded event not fired.");
    }
}

interface LoggerContractInterface
{
}

final class LoggerStub implements LoggerContractInterface
{
    public function __construct(
        public readonly string $value
    ) {
    }
}

final class ConstructorDependentDeferredProvider extends AbstractServiceProvider
{
    protected array $providedServices = [LoggerContractInterface::class];
    public bool $defer = true;

    public function __construct(
        private readonly LoggerContractInterface $dependency
    ) {
    }

    public function register(
        Application $app
    ): void {
        $app->instance(LoggerContractInterface::class, $this->dependency);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
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
    public int $registerCallCount = 0;
    public int $bootCallCount = 0;
    public bool $throwOnRegister = false;
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
        $this->registerCallCount++;

        if ($this->throwOnRegister) {
            throw new RuntimeException('Simulated provider registration failure');
        }

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
        $this->bootCallCount++;
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

class SecondDummyProvider implements ServiceProviderInterface
{
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
    }

    public function boot(
        $app
    ): void {
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

class CountingDeferredService
{
}

class ThrowingDeferredService
{
}

class ThrowingDeferredProvider extends AbstractServiceProvider
{
    public static bool $shouldThrow = true;
    public static int $registerCallCount = 0;
    public static int $bootCallCount = 0;
    protected array $providedServices = [ThrowingDeferredService::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        self::$registerCallCount++;

        if (self::$shouldThrow) {
            throw new RuntimeException('Simulated deferred provider registration failure');
        }

        $app->bind(ThrowingDeferredService::class, fn () => new ThrowingDeferredService(), true);
    }

    public function boot(
        Application $app
    ): void {
        self::$bootCallCount++;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}

class CountingDeferredProvider extends AbstractServiceProvider
{
    public static int $registerCallCount = 0;
    public static int $bootCallCount = 0;
    protected array $providedServices = [CountingDeferredService::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        self::$registerCallCount++;
        $app->bind(CountingDeferredService::class, fn () => new CountingDeferredService(), true);
    }

    public function boot(
        Application $app
    ): void {
        self::$bootCallCount++;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
