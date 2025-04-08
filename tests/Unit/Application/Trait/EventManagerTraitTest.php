<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Application\Interface\SystemEventStoreInterface;
use DomainFlow\Application\Traits\EventManagerTrait;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

#[CoversClass(Application::class)]
#[CoversClass(EventManagerException::class)]
final class EventManagerTraitTest extends TestCase
{
    /**
     * @throws EventManagerException
     */
    public function test_setEventDispatcher(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $this->assertCount(1, $dummyDispatcher->dispatchedEvents);

        $eventRecord = $dummyDispatcher->dispatchedEvents[0];

        $this->assertSame('event_manager.dispatcher.set', $eventRecord['event']);
        $this->assertSame(get_class($dummyDispatcher), $eventRecord['args'][0]);
    }

    /**
     * @throws EventManagerException
     */
    public function test_on_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $listener = function () {};
        $manager->on('test.event', $listener);

        $this->assertArrayHasKey('test.event', $dummyDispatcher->listeners);
        $this->assertSame($listener, $dummyDispatcher->listeners['test.event'][0]);
    }

    /**
     * @throws EventManagerException
     */
    public function test_once_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $listener = function () {};
        $manager->once('once.event', $listener);

        $this->assertArrayHasKey('once.event', $dummyDispatcher->listeners);
        $this->assertCount(1, $dummyDispatcher->listeners['once.event']);
    }

    /**
     * @throws EventManagerException
     */
    public function test_off_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $listener = function () {};
        $manager->on('off.event', $listener);
        $manager->off('off.event', $listener);

        $this->assertArrayNotHasKey('off.event', $dummyDispatcher->listeners);
    }

    /**
     * @throws EventManagerException
     */
    public function test_fireEvent_normal(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $manager->fireEvent('normal.event', 'arg1', 'arg2');
        $this->assertCount(2, $dummyDispatcher->dispatchedEvents);

        $eventRecord = $dummyDispatcher->dispatchedEvents[1];

        $this->assertSame('normal.event', $eventRecord['event']);
        $this->assertSame(['arg1', 'arg2'], $eventRecord['args']);
    }

    /**
     * @throws EventManagerException
     */
    public function test_fireEvent_exception(): void
    {
        $dummyDispatcher = new class() implements EventDispatcherInterface {
            public array $listeners = [];
            public array $dispatchedEvents = [];
            public array $dispatchedErrorEvents = [];
            public bool $throwOnDispatch = true;

            public function on(
                string $event,
                callable $listener
            ): void {
                if (!isset($this->listeners[$event])) {
                    $this->listeners[$event] = [];
                }
                $this->listeners[$event][] = $listener;
            }

            public function once(
                string $event,
                callable $listener
            ): void {
                $this->on($event, $listener);
            }

            public function off(
                string $event,
                callable $listener
            ): void {
                if (!isset($this->listeners[$event])) {
                    return;
                }
                $this->listeners[$event] = array_filter(
                    $this->listeners[$event],
                    fn ($l) => $l !== $listener
                );
                if (empty($this->listeners[$event])) {
                    unset($this->listeners[$event]);
                }
            }

            public function dispatch(
                string $event,
                mixed ...$args
            ): void {
                if ($event !== 'event_manager.dispatcher.set' && $event !== 'event_manager.dispatch.error' && $this->throwOnDispatch) {
                    throw new Exception("Simulated dispatch error");
                }
                $record = [
                    'event' => $event,
                    'args' => $args,
                ];
                if ($event === 'event_manager.dispatch.error') {
                    if (!isset($this->dispatchedErrorEvents[$event])) {
                        $this->dispatchedErrorEvents[$event] = [];
                    }
                    $this->dispatchedErrorEvents[$event][] = $record;
                } else {
                    $this->dispatchedEvents[] = $record;
                }
                if (isset($this->listeners[$event])) {
                    foreach ($this->listeners[$event] as $listener) {
                        $listener(...$args);
                    }
                }
            }

            public function hasListeners(
                string $event
            ): bool {
                return !empty($this->listeners[$event]);
            }
        };

        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $this->expectException(EventManagerException::class);
        try {
            $manager->fireEvent('error.event', 'data');
        } catch (EventManagerException $e) {
            $this->assertArrayHasKey('event_manager.dispatch.error', $dummyDispatcher->dispatchedErrorEvents);

            $errorRecord = $dummyDispatcher->dispatchedErrorEvents['event_manager.dispatch.error'][0];

            $this->assertSame('error.event', $errorRecord['args'][0]);
            $this->assertInstanceOf(Exception::class, $errorRecord['args'][1]);
            $this->assertStringContainsString("Simulated dispatch error", $e->getPrevious()->getMessage());
            throw $e;
        }
    }

    /**
     * @throws EventManagerException
     */
    public function test_hasListeners(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $this->assertFalse($manager->hasListeners('nonexistent.event'));

        $manager->on('existent.event', function () {});

        $this->assertTrue($manager->hasListeners('existent.event'));
    }

    /**
     * @throws EventManagerException
     */
    public function test_getEvents(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $manager = new DummyEventManager();
        $manager->setEventDispatcher($dummyDispatcher);

        $this->assertCount(1, $manager->getEvents());
    }

    public function test_getEventsEmpty(): void
    {
        $manager = new DummyEventManager();
        $this->assertEmpty($manager->getEvents());
    }

    public function test_setEventStore(): void
    {
        $manager = new DummyEventManager();
        $store = new TestDummyEventStore();
        $manager->setEventStore($store);

        $reflection = new ReflectionClass($manager);
        $property = $reflection->getProperty('eventStore');
        $eventStoreValue = $property->getValue($manager);

        $this->assertInstanceOf(
            SystemEventStoreInterface::class,
            $eventStoreValue
        );
    }

}

# Dummy classes
class TestDummyEventStore implements SystemEventStoreInterface
{
    public array $events = [];
    public function addEvent(
        string $eventName,
        array $args
    ): void {
        $this->events[$eventName][] = [
            'order' => count($this->events[$eventName] ?? []),
            'timestamp' => microtime(true),
            'args' => $args,
        ];
    }
    public function getEvents(): array
    {
        return $this->events;
    }

    public function clear(): void
    {
        $this->events = [];
    }
}

class DummyEventManager
{
    use EventManagerTrait;

    public function __construct()
    {
        $this->eventStore = new TestDummyEventStore();
    }
}

class TestDummyEventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $listeners = [];
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $dispatchedEvents = [];
    /**
     * @var array<string, array<int, array<string, mixed>>>
     */
    public array $dispatchedErrorEvents = [];
    public bool $throwOnDispatch = false;

    public function on(
        string $event,
        callable $listener
    ): void {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
    }

    public function once(
        string $event,
        callable $listener
    ): void {
        $this->on($event, $listener);
    }

    public function off(
        string $event,
        callable $listener
    ): void {
        if (!isset($this->listeners[$event])) {
            return;
        }
        $this->listeners[$event] = array_filter(
            $this->listeners[$event],
            fn ($l) => $l !== $listener
        );
        if (empty($this->listeners[$event])) {
            unset($this->listeners[$event]);
        }
    }

    /**
     * @throws Exception
     */
    public function dispatch(
        string $event,
        mixed ...$args
    ): void {
        if ($this->throwOnDispatch) {
            throw new Exception("Simulated dispatch error");
        }
        $record = [
            'event' => $event,
            'args' => $args,
        ];
        if ($event === 'event_manager.dispatch.error') {
            if (!isset($this->dispatchedErrorEvents[$event])) {
                $this->dispatchedErrorEvents[$event] = [];
            }
            $this->dispatchedErrorEvents[$event][] = $record;
        } else {
            $this->dispatchedEvents[] = $record;
        }
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $listener) {
                $listener(...$args);
            }
        }
    }

    public function hasListeners(
        string $event
    ): bool {
        return !empty($this->listeners[$event]);
    }
}
