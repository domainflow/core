<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Attributes\EventListener;
use DomainFlow\Application\Exception\BootstrappingException;
use DomainFlow\Container\Exception\ContainerException;
use DomainFlow\Service\ServiceProviderInterface;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversClass(Application::class)]
#[CoversClass(BootstrappingException::class)]
#[CoversClass(EventDispatcherServiceProvider::class)]
final class BootstrappingTraitTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_normal_boot(): void
    {
        $app = new DummyApplication();

        $bootingCalled = false;
        $bootedCalled = false;
        $app->booting(function (Application $a) use (&$bootingCalled) {
            $bootingCalled = true;
        });
        $app->booted(function (Application $a) use (&$bootedCalled) {
            $bootedCalled = true;
        });

        $app->events = [];
        $app->booted = false;

        $app->boot();

        $this->assertTrue($bootingCalled, 'Booting callback should be called');
        $this->assertTrue($bootedCalled, 'Booted callback should be called');
        $this->assertTrue($app->booted, 'Application should be marked as booted');
        $this->assertEventFired($app->events, 'booting.init');
        $this->assertEventFired($app->events, 'booting.complete');
    }

    /**
     * @throws Throwable
     */
    public function test_boot_with_booting_callback_error(): void
    {
        $app = new DummyApplication();

        $app->booting(function (Application $a) {
            throw new Exception('Booting callback error');
        });

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage('Bootstrapping error: An error occurred during bootstrapping');

        $app->boot();
    }

    /**
     * @throws Throwable
     */
    public function test_boot_with_booted_callback_error(): void
    {
        $app = new DummyApplication();

        $app->booted(function (Application $a) {
            throw new Exception('Booted callback error');
        });

        $this->expectException(BootstrappingException::class);
        $this->expectExceptionMessage('Bootstrapping error: An error occurred during bootstrapping');

        $app->boot();
    }

    public function test_boot_with_provider_registration_error(): void
    {
        $app = new DummyApplication();
        $app->serviceProviders[] = new DummyProviderThrows();

        try {
            $app->boot();
            $this->fail("Expected BootstrappingException was not thrown");
        } catch (BootstrappingException $e) {

            $this->assertStringContainsString('An error occurred during bootstrapping', $e->getMessage());

            $prev = $e->getPrevious();
            $this->assertNotNull($prev, 'Previous exception should be set');

            $this->assertStringContainsString(
                'Failed to register service provider: ' . DummyProviderThrows::class,
                $prev->getMessage()
            );
        }
    }

    /**
     * @throws Throwable
     */
    public function test_boot_retry_after_provider_registration_failure_does_not_reregister_succeeded_providers(): void
    {
        $app = new DummyApplication();

        $succeeding = new DummyCountingProvider();
        $failing = new DummyProviderThrows();

        $app->serviceProviders[] = $succeeding;
        $app->serviceProviders[] = $failing;

        try {
            $app->boot();
            $this->fail('Expected BootstrappingException was not thrown');
        } catch (BootstrappingException) {
            // expected
        }

        $this->assertSame(1, $succeeding->registerCallCount);
        $this->assertSame(0, $succeeding->bootCallCount, 'A later provider failure must not boot an already-registered provider.');
        $this->assertSame(1, $failing->registerCallCount);
        $this->assertFalse($app->booted);

        $failing->shouldThrow = false;
        $app->events = [];
        $app->boot();

        $this->assertSame(
            1,
            $succeeding->registerCallCount,
            'Already-registered provider must not register again on retry.'
        );
        $this->assertSame(1, $succeeding->bootCallCount);
        $this->assertSame(2, $failing->registerCallCount, 'Previously failed provider is retried on the next boot().');
        $this->assertSame(1, $failing->bootCallCount);
        $this->assertTrue($app->booted);
    }

    /**
     * @throws Throwable
     */
    public function test_boot_when_already_booted(): void
    {
        $app = new DummyApplication();
        $app->booted = true;

        $initialCount = count($app->events);
        $app->boot();

        $this->assertSame($initialCount + 1, count($app->events), 'Exactly one new event should be fired when already booted.');
        $this->assertEventFired($app->events, 'booting.repeat_call_ignored');
        $this->assertEventNotFired($app->events, 'booting.init', 'A no-op repeat boot() call must not refire booting.init.');
    }

    private function assertEventFired(array $events, string $expectedEvent): void
    {
        foreach ($events as [$event, $args]) {
            if ($event === $expectedEvent) {
                $this->assertTrue(true);

                return;
            }
        }
        $this->fail("Event {$expectedEvent} was not fired.");
    }

    private function assertEventNotFired(array $events, string $unexpectedEvent, string $message = ''): void
    {
        foreach ($events as [$event, $args]) {
            if ($event === $unexpectedEvent) {
                $this->fail($message !== '' ? $message : "Event {$unexpectedEvent} should not have been fired.");
            }
        }
        $this->assertTrue(true);
    }

    /**
     * @throws ContainerException
     */
    public function test_applyAttributeRegistrations_with_services(): void
    {
        $app = new DummyApplicationEx();
        $app->attributeServiceClasses = [DummyServiceWithAttribute::class];
        $app->callApplyAttributeRegistrations();

        $this->assertTrue($app->autoRegisterServicesCalled, 'autoRegisterServices should be called when attributeServiceClasses is not empty.');
    }

    /**
     * @throws ContainerException
     */
    public function test_applyAttributeRegistrations_with_listeners(): void
    {
        $app = new DummyApplicationEx();
        $app->attributeListenerInstances = [new DummyListenerBootstrappingTrait()];
        $app->callApplyAttributeRegistrations();

        $this->assertTrue($app->autoRegisterEventListenersCalled, 'autoRegisterEventListeners should be called when attributeListenerInstances is not empty.');
    }

    public function test_isBooted_method(): void
    {
        $app = new DummyApplication();
        // Manually set booted flag and check isBooted() returns the correct value
        $app->booted = true;
        $this->assertTrue($app->isBooted(), 'isBooted() should return true when booted is true');

        $app->booted = false;
        $this->assertFalse($app->isBooted(), 'isBooted() should return false when booted is false');
    }

    /**
     * @throws Throwable
     */
    public function test_boot_sets_instance_if_not_already_set(): void
    {
        // An anonymous subclass gives this test a unique static::class key
        // in Container's static $container_instances map, so it cannot
        // collide with another test that has already booted DummyApplication
        // (which would make setInstance() throw LogicException).
        $app = new class() extends DummyApplication {
        };

        $app->boot();

        $this->assertSame(get_class($app), get_class($app::getInstance()));
        $this->assertSame($app, $app::getInstance());
    }

}

# Dummy classes
class DummyApplication extends Application
{
    /**
     * @var array<string, array<int, mixed>>
     */
    public array $events = [];

    public bool $booted = false;

    public array $serviceProviders = [];

    public function __construct()
    {
        parent::__construct();
    }

    public function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->events[] = [$event, $args];
    }

    public function registerProvider(
        $provider
    ): void {
        $this->serviceProviders[] = $provider;
    }
}

class DummyProviderThrows implements ServiceProviderInterface
{
    public bool $shouldThrow = true;
    public int $registerCallCount = 0;
    public int $bootCallCount = 0;

    /**
     * @throws Exception
     */
    public function register(
        Application $app
    ): void {
        $this->registerCallCount++;

        if ($this->shouldThrow) {
            throw new Exception('Provider registration error');
        }
    }

    public function boot(
        Application $app
    ): void {
        $this->bootCallCount++;
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

class DummyCountingProvider implements ServiceProviderInterface
{
    public int $registerCallCount = 0;
    public int $bootCallCount = 0;

    public function register(
        Application $app
    ): void {
        $this->registerCallCount++;
    }

    public function boot(
        Application $app
    ): void {
        $this->bootCallCount++;
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

class DummyApplicationEx extends DummyApplication
{
    public array $attributeServiceClasses = [];
    public array $attributeListenerInstances = [];

    public bool $autoRegisterServicesCalled = false;
    public bool $autoRegisterEventListenersCalled = false;

    public function autoRegisterServices(
        array $classNames
    ): void {
        $this->autoRegisterServicesCalled = true;
    }

    public function autoRegisterEventListeners(
        array $listenerInstances
    ): void {
        $this->autoRegisterEventListenersCalled = true;
    }

    /**
     * @throws ContainerException
     */
    public function callApplyAttributeRegistrations(): void
    {
        $this->applyAttributeRegistrations();
    }
}

class DummyListenerBootstrappingTrait
{
    public bool $called = false;

    #[EventListener('dummy.event')]
    public function onDummyEvent(): void
    {
        $this->called = true;
    }
}
