<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Attributes\EventListener;
use DomainFlow\Application\Attributes\Inject;
use DomainFlow\Application\Attributes\Service;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Container\Exception\ContainerException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionException;
use RuntimeException;
use Throwable;

#[CoversClass(Application::class)]
#[CoversClass(EventListener::class)]
#[CoversClass(Inject::class)]
#[CoversClass(Service::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
final class AttributeRegistrarTraitTest extends TestCase
{
    private Application $app;

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    protected function setUp(): void
    {
        $this->app = new Application();
    }

    /**
     * @throws ContainerException|NotFoundExceptionInterface|ContainerExceptionInterface|Throwable
     */
    public function test_autoRegisterServices_withServiceAttribute(): void
    {
        $this->app->autoRegisterServices([
            DummyServiceWithAttribute::class,
        ]);

        $this->assertTrue($this->app->has('custom_service'));

        $serviceInstance = $this->app->get('custom_service');

        $this->assertInstanceOf(DummyServiceWithAttribute::class, $serviceInstance);
        $this->assertSame($serviceInstance, $this->app->get('custom_service'), 'Service attribute declared shared: true.');
    }

    /**
     * @throws ContainerException
     */
    public function test_autoRegisterServices_withoutServiceAttribute(): void
    {
        $this->app->autoRegisterServices([
            DummyServiceWithoutAttribute::class,
        ]);

        $this->assertFalse($this->app->has(DummyServiceWithoutAttribute::class));
    }

    /**
     * @throws EventManagerException
     */
    public function test_autoRegisterEventListeners(): void
    {
        $listener = new DummyListenerAttributeRegistrarTrait();

        $this->app->autoRegisterEventListeners([$listener]);

        $this->assertTrue($this->app->hasListeners('dummy.event'));

        $this->app->fireEvent('dummy.event');

        $this->assertTrue($listener->called, 'Registered listener was not invoked by the dispatched event.');
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withoutConstructor(): void
    {
        $instance = $this->app->resolveDependencies(DummyNoConstructor::class);

        $this->assertInstanceOf(DummyNoConstructor::class, $instance);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withDependency_withoutInject(): void
    {
        $dummyDependency = new DummyDependency();
        $this->app->instance(DummyDependency::class, $dummyDependency);

        $instance = $this->app->resolveDependencies(DummyWithDependency::class);

        $this->assertInstanceOf(DummyWithDependency::class, $instance);
        $this->assertSame($dummyDependency, $instance->dependency);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withInjectedDependency(): void
    {
        $dummyDependency = new DummyDependency();
        $this->app->instance('custom_dependency', $dummyDependency);

        $instance = $this->app->resolveDependencies(DummyWithInjectedDependency::class);

        $this->assertInstanceOf(DummyWithInjectedDependency::class, $instance);
        $this->assertSame($dummyDependency, $instance->dependency);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withoutTypeThrowsException(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No valid type specified for parameter param');

        $this->app->resolveDependencies(DummyWithoutType::class);
    }
}

# Dummy classes
#[Service(name: 'custom_service', shared: true)]
class DummyServiceWithAttribute
{
}

abstract class DummyServiceWithoutAttribute
{
}

class DummyNoConstructor
{
}

class DummyDependency
{
}

class DummyWithDependency
{
    public DummyDependency $dependency;

    public function __construct(
        DummyDependency $dependency
    ) {
        $this->dependency = $dependency;
    }
}

class DummyWithInjectedDependency
{
    public DummyDependency $dependency;

    public function __construct(#[Inject('custom_dependency')] DummyDependency $dependency)
    {
        $this->dependency = $dependency;
    }
}

class DummyWithoutType
{
    public function __construct(
        $param
    ) {
    }
}

class DummyListenerAttributeRegistrarTrait
{
    public bool $called = false;

    #[EventListener('dummy.event')]
    public function onDummyEvent(): void
    {
        $this->called = true;
    }
}
