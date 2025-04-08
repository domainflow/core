<?php

declare(strict_types=1);

namespace DomainFlow\Application\Class;

use DomainFlow\Application\Interface\SystemEventStoreInterface;

/**
 * Class SystemEventStore
 *
 * A value object that captures and stores system events.
 */
final class SystemEventStore implements SystemEventStoreInterface
{
    /**
     * Stored events.
     *
     * @var array<string, array<int, array{order: int, timestamp: float, args: array<mixed>}>>
     */
    protected array $events = [];

    /**
     * A monotonically increasing order counter.
     *
     * @var int
     */
    protected int $order = 0;

    /**
     * Add an event record to the store.
     *
     * @param string $eventName
     * @param array<int, mixed> $args
     * @return void
     */
    public function addEvent(
        string $eventName,
        array $args
    ): void {
        if (!isset($this->events[$eventName])) {
            $this->events[$eventName] = [];
        }

        $this->events[$eventName][] = [
            'order' => $this->order,
            'timestamp' => microtime(true),
            'args' => $args,
        ];

        $this->order++;
    }

    /**
     * Retrieve all stored events.
     *
     * @return array<string, array<int, array{order: int, timestamp: float, args: array<mixed>}>>
     */
    public function getEvents(): array
    {
        return $this->events;
    }

    /**
     * Clear the store.
     *
     * @return void
     */
    public function clear(): void
    {
        $this->events = [];
        $this->order = 0;
    }

    /**
     * Flatten and sort events by their order.
     *
     * @return array<int, array{eventName: string, order: int, timestamp: float, args: array<mixed>}>
     */
    public function getSortedEvents(): array
    {
        $all = [];
        foreach ($this->events as $eventName => $firings) {
            foreach ($firings as $firing) {
                $all[] = [
                    'eventName' => $eventName,
                    'order' => $firing['order'],
                    'timestamp' => $firing['timestamp'],
                    'args' => $firing['args'],
                ];
            }
        }
        usort($all, static fn ($a, $b) => $a['order'] <=> $b['order']);

        return $all;
    }
}
