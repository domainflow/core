<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Application\Interface\SystemEventStoreInterface;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(EventManagerException::class)]
#[CoversClass(SystemEventStore::class)]
#[CoversClass(BasicEventDispatcher::class)]
final class EventManagerTraitTest extends TestCase
{
    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_setEventDispatcher(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $this->assertCount(1, $dummyDispatcher->dispatchedEvents);

        $eventRecord = $dummyDispatcher->dispatchedEvents[0];

        $this->assertSame('event_manager.dispatcher.set', $eventRecord['event']);
        $this->assertSame(get_class($dummyDispatcher), $eventRecord['args'][0]);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_getEventDispatcher(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $this->assertSame($dummyDispatcher, $app->getEventDispatcher());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_on_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $listener = function () {
        };
        $app->on('test.event', $listener);

        $this->assertArrayHasKey('test.event', $dummyDispatcher->listeners);
        $this->assertSame($listener, $dummyDispatcher->listeners['test.event'][0]);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_once_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $listener = function () {
        };
        $app->once('once.event', $listener);

        $this->assertArrayHasKey('once.event', $dummyDispatcher->listeners);
        $this->assertCount(1, $dummyDispatcher->listeners['once.event']);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_off_method(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $listener = function () {
        };
        $app->on('off.event', $listener);
        $app->off('off.event', $listener);

        $this->assertArrayNotHasKey('off.event', $dummyDispatcher->listeners);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_fireEvent_normal(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $app->fireEvent('normal.event', 'arg1', 'arg2');
        $this->assertCount(2, $dummyDispatcher->dispatchedEvents);

        $eventRecord = $dummyDispatcher->dispatchedEvents[1];

        $this->assertSame('normal.event', $eventRecord['event']);
        $this->assertSame(['arg1', 'arg2'], $eventRecord['args']);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_fireEvent_exception(): void
    {
        $dummyDispatcher = new class() implements EventDispatcherInterface {
            public array $listeners = [];
            public array $dispatchedEvents = [];
            public array $dispatchedErrorEvents = [];

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
                if ($event !== 'event_manager.dispatcher.set' && $event !== 'event_manager.dispatch.error') {
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

        $app = new Application(eventDispatcher: $dummyDispatcher);

        $this->expectException(EventManagerException::class);
        try {
            $app->fireEvent('error.event', 'data');
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
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_hasListeners(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $this->assertFalse($app->hasListeners('nonexistent.event'));

        $app->on('existent.event', function () {
        });

        $this->assertTrue($app->hasListeners('existent.event'));
    }

    public function test_hasListeners_includes_wildcard_listeners_used_by_dispatch(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $global = static function (): void {
        };
        $orders = static function (): void {
        };
        $dispatcher->on('orders.*', $orders);

        $this->assertTrue($dispatcher->hasListeners('orders.created'));
        $this->assertFalse($dispatcher->hasListeners('users.deleted'));

        $dispatcher->off('orders.*', $orders);
        $this->assertFalse($dispatcher->hasListeners('orders.created'));

        $dispatcher->on('*', $global);
        $this->assertTrue($dispatcher->hasListeners('users.deleted'));

        $dispatcher->off('*', $global);
        $this->assertFalse($dispatcher->hasListeners('users.deleted'));
    }

    public function test_error_listener_failure_does_not_mask_the_original_dispatch_exception(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $app = new Application(eventDispatcher: $dispatcher);
        $original = new Exception('original listener failure');
        $dispatcher->on('business.event', static function () use ($original): never {
            throw $original;
        });
        $dispatcher->on('event_manager.dispatch.error', static function (): never {
            throw new Exception('secondary error listener failure');
        });

        try {
            $app->fireEvent('business.event');
            $this->fail('The original listener failure must be wrapped.');
        } catch (EventManagerException $exception) {
            $this->assertSame($original, $exception->getPrevious());
        }
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_getEvents(): void
    {
        $dummyDispatcher = new TestDummyEventDispatcher();
        $app = new Application(eventDispatcher: $dummyDispatcher);

        $this->assertCount(1, $app->getEvents());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_setEventStore(): void
    {
        $app = new Application();
        $store = new TestDummyEventStore();

        $app->setEventStore($store);
        $app->fireEvent('probe.event', 'probe-arg');

        $this->assertArrayHasKey('probe.event', $store->getEvents());
        $this->assertSame('probe-arg', $store->getEvents()['probe.event'][0]['args'][0]);
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
