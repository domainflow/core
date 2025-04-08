<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Class;

use DomainFlow\Application\Class\BasicEventDispatcher;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BasicEventDispatcher::class)]
final class BasicEventDispatcherTest extends TestCase
{
    public function test_on_and_dispatch(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $counter = 0;
        $listener = function ($arg) use (&$counter) {
            $counter += $arg;
        };

        $dispatcher->on('increment', $listener);
        $dispatcher->dispatch('increment', 5);
        $this->assertSame(5, $counter);

        $dispatcher->dispatch('increment', 3);
        $this->assertSame(8, $counter);
    }

    public function test_once(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $counter = 0;
        $listener = function ($arg) use (&$counter) {
            $counter += $arg;
        };

        $dispatcher->once('once_event', $listener);

        $dispatcher->dispatch('once_event', 10);
        $this->assertSame(10, $counter);

        $dispatcher->dispatch('once_event', 10);
        $this->assertSame(10, $counter);
    }

    public function test_off(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $counter = 0;
        $listener = function ($arg) use (&$counter) {
            $counter += $arg;
        };

        $dispatcher->on('remove_event', $listener);
        $dispatcher->dispatch('remove_event', 5);
        $this->assertSame(5, $counter);

        $dispatcher->off('remove_event', $listener);

        $dispatcher->dispatch('remove_event', 5);
        $this->assertSame(5, $counter);
    }

    public function test_off_nonexistent_event(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $dummy = function () {
        };

        $dispatcher->off('nonexistent', $dummy);
        $this->assertFalse($dispatcher->hasListeners('nonexistent'));
    }

    public function test_dispatch_wildcard(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $counter = 0;
        $listener = function ($arg) use (&$counter) {
            $counter += $arg;
        };

        $dispatcher->on('user.*', $listener);

        $dispatcher->dispatch('user.login', 7);
        $this->assertSame(7, $counter);

        $dispatcher->dispatch('user.logout', 3);
        $this->assertSame(10, $counter);
    }

    public function test_dispatch_global_wildcard(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $received = [];

        $listener = function ($eventName, $payload) use (&$received) {
            $received[] = [$eventName, $payload];
        };

        $dispatcher->on('*', $listener);

        $dispatcher->dispatch('custom.event', 'data');

        $this->assertCount(1, $received);
        $this->assertSame(['custom.event', 'data'], $received[0]);
    }

    public function test_multiple_listeners_and_hasListeners(): void
    {
        $dispatcher = new BasicEventDispatcher();
        $counter1 = 0;
        $counter2 = 0;
        $listener1 = function ($arg) use (&$counter1) {
            $counter1 += $arg;
        };
        $listener2 = function ($arg) use (&$counter2) {
            $counter2 += $arg;
        };

        $this->assertFalse($dispatcher->hasListeners('multi'));

        $dispatcher->on('multi', $listener1);
        $dispatcher->on('multi', $listener2);
        $this->assertTrue($dispatcher->hasListeners('multi'));

        $dispatcher->dispatch('multi', 4);
        $this->assertSame(4, $counter1);
        $this->assertSame(4, $counter2);

        $dispatcher->off('multi', $listener1);
        $this->assertTrue($dispatcher->hasListeners('multi'));

        $dispatcher->off('multi', $listener2);
        $this->assertFalse($dispatcher->hasListeners('multi'));
    }
}
