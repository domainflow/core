<?php

declare(strict_types=1);

namespace DomainFlow\Application\Interface;

interface SystemEventStoreInterface
{
    /**
     * Add an event to the store.
     *
     * @param string $eventName
     * @param array<mixed> $args
     * @return void
     */
    public function addEvent(string $eventName, array $args): void;

    /**
     * Get all stored events.
     *
     * @return array<string, array<int, array{order: int, timestamp: float, args: array<mixed>}>>
     */
    public function getEvents(): array;

    /**
     * Clear all stored events.
     *
     * @return void
     */
    public function clear(): void;
}
