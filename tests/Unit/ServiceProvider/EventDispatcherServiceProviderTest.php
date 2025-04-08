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
use ReflectionClass;

#[CoversClass(EventDispatcherServiceProvider::class)]
#[CoversClass(Application::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
class EventDispatcherServiceProviderTest extends TestCase
{
    private function getEventDispatcher(Application $app): ?object
    {
        $reflection = new ReflectionClass($app);
        $property = $reflection->getProperty('eventDispatcher');

        return $property->getValue($app);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_registerSetsEventDispatcher(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $dummyDispatcher = new BasicEventDispatcher();
        $app->setEventDispatcher($dummyDispatcher);

        $provider->register($app);
        $newDispatcher = $this->getEventDispatcher($app);

        $this->assertInstanceOf(BasicEventDispatcher::class, $newDispatcher);
        $this->assertSame($dummyDispatcher, $newDispatcher);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_bootDoesNothingUnexpected(): void
    {
        $app = new TestApplication(sys_get_temp_dir());
        $provider = new EventDispatcherServiceProvider();

        $provider->register($app);
        $dispatcherBeforeBoot = $this->getEventDispatcher($app);

        $provider->boot($app);
        $dispatcherAfterBoot = $this->getEventDispatcher($app);

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
