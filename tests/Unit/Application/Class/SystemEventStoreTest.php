<?php

declare(strict_types=1);

namespace Application\Class;

use DomainFlow\Application\Class\SystemEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(SystemEventStore::class)]
final class SystemEventStoreTest extends TestCase
{
    public function test_initialState(): void
    {
        $store = new SystemEventStore();
        $this->assertSame([], $store->getEvents(), 'New store should have no events');
        $this->assertSame([], $store->getSortedEvents(), 'Sorted events should be empty');
    }

    public function test_addEventSingleEvent(): void
    {
        $store = new SystemEventStore();
        $args = ['foo', 'bar'];
        $store->addEvent('test', $args);

        $events = $store->getEvents();
        $this->assertArrayHasKey('test', $events, 'Event key "test" should exist');
        $this->assertCount(1, $events['test'], 'There should be exactly one firing for "test"');

        $event = $events['test'][0];
        $this->assertSame(0, $event['order'], 'First event order should be 0');
        $this->assertIsFloat($event['timestamp'], 'Timestamp should be a float');
        $this->assertSame($args, $event['args'], 'Event args should match the given array');
    }

    public function test_addEventMultipleEventsSameName(): void
    {
        $store = new SystemEventStore();
        $store->addEvent('test', [1]);
        $store->addEvent('test', [2]);

        $events = $store->getEvents();
        $this->assertArrayHasKey('test', $events);
        $this->assertCount(2, $events['test'], 'There should be two firings for "test"');

        $this->assertSame(0, $events['test'][0]['order'], 'First firing order should be 0');
        $this->assertSame(1, $events['test'][1]['order'], 'Second firing order should be 1');

        $sorted = $store->getSortedEvents();
        $this->assertCount(2, $sorted);
        $this->assertSame('test', $sorted[0]['eventName']);
        $this->assertSame(0, $sorted[0]['order']);
        $this->assertSame([1], $sorted[0]['args']);

        $this->assertSame('test', $sorted[1]['eventName']);
        $this->assertSame(1, $sorted[1]['order']);
        $this->assertSame([2], $sorted[1]['args']);
    }

    public function test_addEventDifferentNames(): void
    {
        $store = new SystemEventStore();
        $store->addEvent('alpha', ['a']);
        $store->addEvent('beta', ['b']);
        $store->addEvent('alpha', ['c']);

        $events = $store->getEvents();
        $this->assertArrayHasKey('alpha', $events);
        $this->assertArrayHasKey('beta', $events);
        $this->assertCount(2, $events['alpha'], 'Alpha should have two events');
        $this->assertCount(1, $events['beta'], 'Beta should have one event');

        $this->assertSame(0, $events['alpha'][0]['order']);
        $this->assertSame(1, $events['beta'][0]['order']);
        $this->assertSame(2, $events['alpha'][1]['order']);

        $sorted = $store->getSortedEvents();
        $this->assertCount(3, $sorted);

        $this->assertSame(0, $sorted[0]['order']);
        $this->assertSame('alpha', $sorted[0]['eventName']);

        $this->assertSame(1, $sorted[1]['order']);
        $this->assertSame('beta', $sorted[1]['eventName']);

        $this->assertSame(2, $sorted[2]['order']);
        $this->assertSame('alpha', $sorted[2]['eventName']);
    }

    public function test_clear(): void
    {
        $store = new SystemEventStore();
        $store->addEvent('event1', ['data1']);
        $store->addEvent('event2', ['data2']);

        $this->assertNotEmpty($store->getEvents(), 'Store should have events before clearing');
        $store->clear();
        $this->assertSame([], $store->getEvents(), 'Store should be empty after clearing');
        $this->assertSame([], $store->getSortedEvents(), 'Sorted events should be empty after clearing');

        $store->addEvent('new', ['newData']);
        $events = $store->getEvents();
        $this->assertArrayHasKey('new', $events);
        $this->assertSame(0, $events['new'][0]['order'], 'Order should restart at 0 after clear');
    }
}
