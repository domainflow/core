<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Attributes\EventListener;
use DomainFlow\Application\Attributes\Inject;
use DomainFlow\Application\Attributes\Service;
use DomainFlow\Application\Traits\AttributeRegistrarTrait;
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
final class AttributeRegistrarTraitTest extends TestCase
{
    private DummyEventDispatcher $dummyDispatcher;

    protected function setUp(): void
    {
        $this->dummyDispatcher = new DummyEventDispatcher();
    }

    /**
     * @throws ContainerException
     */
    public function test_autoRegisterServices_withServiceAttribute(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $container->bindingsRecords = [];

        $container->autoRegisterServices([
            DummyServiceWithAttribute::class,
        ]);

        $this->assertArrayHasKey('custom_service', $container->bindingsRecords);

        $record = $container->bindingsRecords['custom_service'];

        $this->assertTrue($record['shared']);

        $serviceInstance = ($record['closure'])();

        $this->assertInstanceOf(DummyServiceWithAttribute::class, $serviceInstance);
    }

    /**
     * @throws ContainerException
     */
    public function test_autoRegisterServices_withoutServiceAttribute(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $container->bindingsRecords = [];

        $container->autoRegisterServices([
            DummyServiceWithoutAttribute::class,
        ]);

        $this->assertEmpty($container->bindingsRecords);
    }

    public function test_autoRegisterEventListeners(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $listener = new DummyListenerAttributeRegistrarTrait();

        $container->autoRegisterEventListeners([$listener]);

        $this->assertArrayHasKey('dummy.event', $this->dummyDispatcher->listeners);

        $callbacks = $this->dummyDispatcher->listeners['dummy.event'];

        $found = false;
        foreach ($callbacks as $callback) {
            if ($callback === [$listener, 'onDummyEvent']) {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found, "Expected listener callback not found");
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withoutConstructor(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $instance = $container->resolveDependencies(DummyNoConstructor::class);

        $this->assertInstanceOf(DummyNoConstructor::class, $instance);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withDependency_withoutInject(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $dummyDependency = new DummyDependency();
        $container->getBindings[DummyDependency::class] = $dummyDependency;

        $instance = $container->resolveDependencies(DummyWithDependency::class);

        $this->assertInstanceOf(DummyWithDependency::class, $instance);
        $this->assertSame($dummyDependency, $instance->dependency);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|ContainerExceptionInterface|ReflectionException
     */
    public function test_resolveDependencies_withInjectedDependency(): void
    {
        $container = new DummyContainer($this->dummyDispatcher);
        $dummyDependency = new DummyDependency();
        $container->getBindings['custom_dependency'] = $dummyDependency;

        $instance = $container->resolveDependencies(DummyWithInjectedDependency::class);

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

        $container = new DummyContainer($this->dummyDispatcher);

        $container->resolveDependencies(DummyWithoutType::class);
    }
}

# Dummy classes
class DummyEventDispatcher
{
    public array $listeners = [];

    public function on(
        string $event,
        callable $callback
    ): void {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $callback;
    }
}

class DummyContainer
{
    use AttributeRegistrarTrait;

    /** @var array<string, array{closure: callable, shared: bool}> */
    public array $bindingsRecords = [];
    /** @var array<string, mixed> */
    public array $getBindings = [];
    public DummyEventDispatcher $eventDispatcher;

    public function __construct(
        DummyEventDispatcher $dispatcher
    ) {
        $this->eventDispatcher = $dispatcher;
    }

    public function bind(
        string $name,
        callable $closure,
        bool $shared
    ): void {
        $this->bindingsRecords[$name] = [
            'closure' => $closure,
            'shared' => $shared,
        ];
    }

    public function get(
        string $id
    ) {
        if (isset($this->getBindings[$id])) {
            return $this->getBindings[$id];
        }
        if (isset($this->bindingsRecords[$id])) {
            return ($this->bindingsRecords[$id]['closure'])();
        }
        throw new RuntimeException("No binding for {$id}");
    }
}

#[Service(name: 'custom_service', shared: true)]
class DummyServiceWithAttribute
{
}

class DummyServiceWithoutAttribute
{
}

class DummyListener
{
    public bool $called = false;

    #[EventListener('dummy.event')]
    public function onDummyEvent(): void
    {
        $this->called = true;
    }
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
