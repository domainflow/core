<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\ServiceProvider;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;

#[CoversClass(EventDispatcherServiceProvider::class)]
#[CoversClass(Application::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
class EventDispatcherServiceProviderTest extends TestCase
{
    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_registerDoesNotReplaceTheApplicationsEventDispatcher(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $dummyDispatcher = new BasicEventDispatcher();
        $app->setEventDispatcher($dummyDispatcher);

        $provider->register($app);
        $newDispatcher = $app->getEventDispatcher();

        $this->assertInstanceOf(BasicEventDispatcher::class, $newDispatcher);
        $this->assertSame($dummyDispatcher, $newDispatcher);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function test_registerBindsTheApplicationsOwnDispatcherAsEventDispatcherInterface(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $dummyDispatcher = new BasicEventDispatcher();
        $app->setEventDispatcher($dummyDispatcher);

        $provider->register($app);

        $this->assertSame($dummyDispatcher, $app->get(EventDispatcherInterface::class));
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_bootDoesNothingUnexpected(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $provider->register($app);
        $dispatcherBeforeBoot = $app->getEventDispatcher();

        $provider->boot($app);
        $dispatcherAfterBoot = $app->getEventDispatcher();

        $this->assertSame($dispatcherBeforeBoot, $dispatcherAfterBoot);
    }

    public function test_providesReturnsExpectedServices(): void
    {
        $provider = new EventDispatcherServiceProvider();
        $expected = [EventDispatcherInterface::class];
        $this->assertEquals($expected, $provider->provides());
    }

    public function test_isDeferredReturnsFalse(): void
    {
        $provider = new EventDispatcherServiceProvider();
        $this->assertFalse($provider->isDeferred());
    }

    public function test_isDeferredReturnsTrue(): void
    {
        $provider = new EventDispatcherServiceProvider();
        $provider->defer = true;
        $this->assertTrue($provider->isDeferred());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function test_containerBindingReflectsDispatcherSwappedAfterRegister(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $provider->register($app);

        $swapped = new BasicEventDispatcher();
        $app->setEventDispatcher($swapped);

        $this->assertSame(
            $swapped,
            $app->get(EventDispatcherInterface::class),
            'The container binding must resolve the dispatcher current at resolution time, not the one active at register() time.'
        );
    }
}

// dummy class
class TestApplication extends Application
{
    public function fireEvent(
        string $event,
        mixed ...$args
    ): void {
        if (!isset($this->eventDispatcher)) {
            return;
        }
        parent::fireEvent($event, ...$args);
    }
}
