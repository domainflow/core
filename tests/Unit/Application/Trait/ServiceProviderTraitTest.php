<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\BootstrappingException;
use DomainFlow\Service\AbstractServiceProvider;
use DomainFlow\Service\OrderedServiceProviderInterface;
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
        $app = new Application();
        $provider = new DummyProvider(['service1']);
        $provider->defer = true;
        $class = get_class($provider);

        $app->registerProvider($provider);

        $this->assertFalse($app->has('service1'), 'A deferred service is not bound until requested.');
        $this->assertFalse($app->hasProvider($class));

        $value = $app->get('service1');

        $this->assertTrue($app->hasProvider($class));
        $this->assertEquals('default_service1', $value);
        $this->assertEventFiredWithArgs($app, 'service_provider.deferred.loaded', ['service1', $class]);
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderRegistersAndFiresEvent_nonDefer(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service1']);
        $class = get_class($provider);

        $app->registerProvider($provider);

        $this->assertTrue($app->hasProvider($class));

        $value = $app->get('service1');
        $this->assertEquals('default_service1', $value);

        $this->assertEventFiredWithArgs($app, 'service_provider.registered', [$class]);
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderDoesNotRegisterTwice(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service1']);

        $app->registerProvider($provider);
        $app->registerProvider($provider);

        $this->assertCount(1, $app->getProviders());
        $this->assertSame(1, $provider->registerCallCount, 'A provider class must not register twice.');
    }

    /**
     * @throws Throwable
     */
    public function test_registerProviderBootsIfAlreadyBooted(): void
    {
        $app = new Application();
        $app->boot();
        $provider = new DummyProvider(['service2']);

        $app->registerProvider($provider);

        $this->assertTrue($provider->bootedCalled);
    }

    /**
     * @throws Throwable
     */
    public function test_unregisterProviderRemovesProvider(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service3', 'service4']);
        $provider->defer = true;
        $class = get_class($provider);
        $app->registerProvider($provider);

        $app->unregisterProvider($class);

        $this->assertFalse($app->hasProvider($class));
        $this->assertEventFiredWithArgs($app, 'service_provider.unregistered', [$class]);
    }

    /**
     * @throws Throwable
     */
    public function test_unregisterProviderRemovesProviderAndDeferredServicesCompletely(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service9', 'service10']);
        $provider->defer = false;
        $providerClass = get_class($provider);

        $app->registerProvider($provider);

        $this->assertTrue($app->hasProvider($providerClass));

        $app->unregisterProvider($providerClass);

        $this->assertFalse($app->hasProvider($providerClass));
    }

    /**
     * @throws Throwable
     */
    public function test_getProviders(): void
    {
        $app = new Application();
        $provider = $this->createStub(DummyProvider::class);
        $app->registerProvider($provider);

        $providers = $app->getProviders();
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
        $app = new Application();
        $provider = new DummyProvider(['service8']);
        $class = get_class($provider);
        $this->assertFalse($app->hasProvider($class));
        $app->registerProvider($provider);
        $this->assertTrue($app->hasProvider($class));
    }

    /**
     * @throws Throwable
     */
    public function test_registerProvider_deferredIdentifierCollision_throwsAndPreservesFirstClaim(): void
    {
        $app = new Application();
        $first = new DummyProvider(['shared.service'], true);
        $second = new SecondDummyProvider(['shared.service'], true);

        $app->registerProvider($first);

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage(sprintf(
            'Deferred service identifier [shared.service] is already claimed by [%s] and cannot also be claimed by [%s].',
            get_class($first),
            get_class($second)
        ));

        try {
            $app->registerProvider($second);
        } finally {
            $value = $app->get('shared.service');

            $this->assertSame(
                'default_shared.service',
                $value,
                'The first claiming provider must remain the owner of the identifier after a rejected collision.'
            );
            $this->assertSame(1, $first->registerCallCount);
            $this->assertFalse($app->hasProvider(get_class($second)));
        }
    }

    /**
     * @throws Throwable
     */
    public function test_registerProvider_sameDeferredProviderClassTwice_isNotACollision(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service1'], true);

        $app->registerProvider($provider);
        $app->registerProvider($provider);

        $value = $app->get('service1');

        $this->assertSame('default_service1', $value);
        $this->assertSame(1, $provider->registerCallCount);
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
        $app = new Application();
        $first = new DummyProvider(['reusable.service'], true);
        $second = new DummyProvider(['reusable.service'], true);

        $app->registerProvider($first);
        $app->unregisterProvider(get_class($first));

        // Would throw BootstrappingException::forDeferredServiceIdentifierCollision()
        // if the identifier claim had not been released by unregisterProvider().
        $app->registerProvider($second);

        $value = $app->get('reusable.service');

        $this->assertSame('default_reusable.service', $value);
        $this->assertSame(1, $second->registerCallCount);
        $this->assertSame(0, $first->registerCallCount);
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
        $app = new Application();
        $ref = new ReflectionClass($app);
        $ref->getProperty('deferredServices')->setValue($app, ['service.orphaned' => DummyProvider::class]);

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage(
            'No registered instance found for deferred provider [' . DummyProvider::class . '] '
            . 'while resolving service [service.orphaned].'
        );

        $app->get('service.orphaned');
    }

    /**
     * @throws Throwable
     */
    public function test_loadDeferredProviders_preWarmsRegisteredDeferredProvider(): void
    {
        $provider = new DummyProvider(['service6'], true);

        $app = new Application();
        $app->registerProvider($provider);

        $app->loadDeferredProviders();

        $this->assertTrue($app->has('service6'));
        $this->assertSame(1, $provider->registerCallCount);
        $this->assertEventFiredWithArgs($app, 'service_provider.deferred.loaded', ['service6', get_class($provider)]);
    }

    /**
     * @throws Throwable
     */
    public function test_bootOrderRespectsDeclaredDependencyDespiteReverseRegistrationOrder(): void
    {
        OrderedProviderLog::reset();
        $app = new Application();

        $b = new OrderedProviderB();
        $a = new OrderedProviderA();

        $app->registerProvider($b);
        $app->registerProvider($a);

        $app->boot();

        $registerLog = array_values(array_filter(
            OrderedProviderLog::$log,
            static fn (string $entry): bool => str_ends_with($entry, '::register')
        ));
        $this->assertSame(
            [OrderedProviderB::class . '::register', OrderedProviderA::class . '::register'],
            $registerLog,
            'register() runs immediately at registerProvider() call time, in call order — declared '
            . 'ordering only reorders boot(), which has not run yet at that point. This is intentional '
            . 'and documented in docs/ARCHITECTURE.md.'
        );

        $bootLog = array_values(array_filter(
            OrderedProviderLog::$log,
            static fn (string $entry): bool => str_ends_with($entry, '::boot')
        ));
        $this->assertSame(
            [OrderedProviderA::class . '::boot', OrderedProviderB::class . '::boot'],
            $bootLog,
            'boot() must respect the declared dependency: A (the dependency) boots before B (the dependent), regardless of registration order.'
        );
    }

    /**
     * @throws Throwable
     */
    public function test_diamondDependencyOrdersAllPrerequisitesBeforeDependent(): void
    {
        OrderedProviderLog::reset();
        $app = new Application();

        // Scrambled registration order: C (depends on A, B) before B (depends on A) before A.
        $app->registerProvider(new OrderedProviderC());
        $app->registerProvider(new OrderedProviderB());
        $app->registerProvider(new OrderedProviderA());

        $app->boot();

        $bootLog = array_values(array_filter(
            OrderedProviderLog::$log,
            static fn (string $entry): bool => str_ends_with($entry, '::boot')
        ));
        $this->assertSame(
            [OrderedProviderA::class . '::boot', OrderedProviderB::class . '::boot', OrderedProviderC::class . '::boot'],
            $bootLog,
            'A diamond dependency (C depends on A and B; B depends on A) must still produce a single valid topological order.'
        );
    }

    /**
     * @throws Throwable
     */
    public function test_providersWithNoDeclaredDependenciesKeepInsertionOrder(): void
    {
        DummyOrderProviderFirst::$bootOrder = [];
        $app = new Application();

        $app->registerProvider(new DummyOrderProviderFirst());
        $app->registerProvider(new DummyOrderProviderSecond());

        $app->boot();

        $this->assertSame(
            [DummyOrderProviderFirst::class, DummyOrderProviderSecond::class],
            DummyOrderProviderFirst::$bootOrder,
            'Providers declaring no dependencies must boot in plain insertion order, unchanged from before this feature.'
        );
    }

    /**
     * @throws Throwable
     */
    public function test_deferredProviderResolvedBeforeBootIsNotRebootedWhenBootRuns(): void
    {
        $app = new Application();
        $provider = new DummyProvider(['service_resolved_early'], true);
        $app->registerProvider($provider);

        // Resolving the deferred provider before boot() registers and boots
        // it immediately via resolveDeferredProvider().
        $app->get('service_resolved_early');
        $this->assertSame(1, $provider->bootCallCount);

        // boot()'s ordering pass sees this now-registered provider again;
        // it must not be booted a second time.
        $app->boot();
        $this->assertSame(1, $provider->bootCallCount, 'A provider already booted before boot() must not be re-booted.');
    }

    public function test_providerDependencyCycleThrowsClearBootstrappingException(): void
    {
        $app = new Application();
        $app->registerProvider(new CyclicProviderX());
        $app->registerProvider(new CyclicProviderY());

        try {
            $app->boot();
            $this->fail('Expected BootstrappingException was not thrown');
        } catch (BootstrappingException $e) {
            $prev = $e->getPrevious();
            $this->assertNotNull($prev, 'Previous exception should carry the specific cycle detail.');
            $this->assertMatchesRegularExpression(
                '/dependency cycle detected involving \[' . preg_quote(CyclicProviderX::class, '/') . '\]/',
                $prev->getMessage()
            );
        }
    }

    public function test_providerDependencyOnNeverRegisteredProviderClassThrows(): void
    {
        $app = new Application();
        $app->registerProvider(new DependsOnUnregisteredProvider());

        try {
            $app->boot();
            $this->fail('Expected BootstrappingException was not thrown');
        } catch (BootstrappingException $e) {
            $prev = $e->getPrevious();
            $this->assertNotNull($prev, 'Previous exception should carry the specific dependency detail.');
            $this->assertSame(
                'Service provider [' . DependsOnUnregisteredProvider::class . '] declares a dependency on '
                . '[' . SecondDummyProvider::class . '], which was never registered.',
                $prev->getMessage()
            );
        }
    }

    /**
     * @param array<int, mixed> $expectedArgs
     */
    private function assertEventFiredWithArgs(
        Application $app,
        string $event,
        array $expectedArgs
    ): void {
        foreach ($app->getEvents()[$event] ?? [] as $entry) {
            if ($entry['args'] === $expectedArgs) {
                return;
            }
        }
        $this->fail(sprintf('Event [%s] with the expected arguments was not fired.', $event));
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
class DummyProvider implements ServiceProviderInterface
{
    public bool $registered = false;
    public bool $bootedCalled = false;
    public int $registerCallCount = 0;
    public int $bootCallCount = 0;
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
        Application $app
    ): void {
        $this->registerCallCount++;
        $this->registered = true;

        foreach ($this->provides as $service) {
            $app->bind($service, fn () => "default_$service");
        }
    }

    public function boot(
        Application $app
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
        Application $app
    ): void {
    }

    public function boot(
        Application $app
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

class OrderedProviderLog
{
    /** @var list<string> */
    public static array $log = [];

    public static function reset(): void
    {
        self::$log = [];
    }
}

class OrderedProviderA implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::register';
    }

    public function boot(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::boot';
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [];
    }
}

class OrderedProviderB implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::register';
    }

    public function boot(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::boot';
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [OrderedProviderA::class];
    }
}

class OrderedProviderC implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::register';
    }

    public function boot(
        Application $app
    ): void {
        OrderedProviderLog::$log[] = self::class . '::boot';
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [OrderedProviderA::class, OrderedProviderB::class];
    }
}

class DummyOrderProviderFirst implements ServiceProviderInterface
{
    /** @var list<string> */
    public static array $bootOrder = [];

    public function register(
        Application $app
    ): void {
    }

    public function boot(
        Application $app
    ): void {
        self::$bootOrder[] = self::class;
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }
}

class DummyOrderProviderSecond implements ServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
    }

    public function boot(
        Application $app
    ): void {
        DummyOrderProviderFirst::$bootOrder[] = self::class;
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }
}

class CyclicProviderX implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
    }

    public function boot(
        Application $app
    ): void {
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [CyclicProviderY::class];
    }
}

class CyclicProviderY implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
    }

    public function boot(
        Application $app
    ): void {
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [CyclicProviderX::class];
    }
}

class DependsOnUnregisteredProvider implements OrderedServiceProviderInterface
{
    public function register(
        Application $app
    ): void {
    }

    public function boot(
        Application $app
    ): void {
    }

    public function provides(): array
    {
        return [];
    }

    public function isDeferred(): bool
    {
        return false;
    }

    public function dependsOn(): array
    {
        return [SecondDummyProvider::class];
    }
}
