<?php

declare(strict_types=1);

namespace DomainFlow\Application\Interface;

interface EventDispatcherInterface
{
    /**
     * Register an event listener.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function on(string $event, callable $listener): void;

    /**
     * Register an event listener that will be called only once.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function once(string $event, callable $listener): void;

    /**
     * Remove an event listener.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function off(string $event, callable $listener): void;

    /**
     * Dispatch an event.
     *
     * @param string $event
     * @param mixed ...$args
     * @return void
     */
    public function dispatch(string $event, mixed ...$args): void;

    /**
     * Check if an event has listeners.
     *
     * @param string $event
     * @return bool
     */
    public function hasListeners(string $event): bool;
}
