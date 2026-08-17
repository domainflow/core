<?php

declare(strict_types=1);

namespace DomainFlow\Application\Class;

use DomainFlow\Application\Interface\SystemEventStoreInterface;
use InvalidArgumentException;

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
     * Order => event name, oldest-first, for O(1) FIFO eviction lookups.
     *
     * @var array<int, string>
     */
    private array $orderToEventName = [];

    /**
     * Total firings currently retained across all event names.
     *
     * @var int
     */
    private int $totalCount = 0;

    /**
     * Caps total retained firings across all event names combined. Once
     * addEvent() would exceed it, the globally oldest firing is evicted —
     * dropped, not drained anywhere — before the new one is appended. Null
     * (the default, unless setMaxRetainedEvents() is called) is unbounded,
     * the pre-existing behaviour.
     *
     * @var int|null
     */
    private ?int $maxRetainedEvents = null;

    /**
     * Cap total retained firings across all event names combined, or pass
     * null to restore unbounded retention. Intended for a long-running
     * process (worker, daemon, queue consumer) that would otherwise
     * accumulate every fired event in memory for its entire lifetime.
     *
     * @param int|null $maxRetainedEvents
     * @throws InvalidArgumentException if not null and not a positive integer.
     * @return void
     */
    public function setMaxRetainedEvents(
        ?int $maxRetainedEvents
    ): void {
        if ($maxRetainedEvents !== null && $maxRetainedEvents < 1) {
            throw new InvalidArgumentException('maxRetainedEvents must be a positive integer or null.');
        }

        $this->maxRetainedEvents = $maxRetainedEvents;
    }

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
        if ($this->maxRetainedEvents !== null && $this->totalCount >= $this->maxRetainedEvents) {
            $this->evictOldest();
        }

        if (!isset($this->events[$eventName])) {
            $this->events[$eventName] = [];
        }

        $this->events[$eventName][] = [
            'order' => $this->order,
            'timestamp' => microtime(true),
            'args' => $args,
        ];
        $this->orderToEventName[$this->order] = $eventName;
        $this->totalCount++;

        $this->order++;
    }

    /**
     * Evict the globally oldest retained firing to make room under the cap.
     *
     * @return void
     */
    private function evictOldest(): void
    {
        /** @var int $oldestOrder addEvent() only calls this when orderToEventName is non-empty. */
        $oldestOrder = array_key_first($this->orderToEventName);
        $eventName = $this->orderToEventName[$oldestOrder];

        unset($this->orderToEventName[$oldestOrder]);
        array_shift($this->events[$eventName]);
        if ($this->events[$eventName] === []) {
            unset($this->events[$eventName]);
        }

        $this->totalCount--;
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
        $this->orderToEventName = [];
        $this->totalCount = 0;
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
