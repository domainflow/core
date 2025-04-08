<?php

declare(strict_types=1);

namespace DomainFlow\Application\Class;

use DomainFlow\Application\Interface\EventDispatcherInterface;

/**
 * Class BasicEventDispatcher
 *
 * A basic event dispatcher implementation.
 */
class BasicEventDispatcher implements EventDispatcherInterface
{
    /**
     * @var array<string, array<int, callable>> $listeners
     */
    protected array $listeners = [];

    /**
     * Register an event listener.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function on(
        string $event,
        callable $listener
    ): void {
        if (!isset($this->listeners[$event])) {
            $this->listeners[$event] = [];
        }
        $this->listeners[$event][] = $listener;
    }

    /**
     * Register an event listener that will be called only once.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function once(
        string $event,
        callable $listener
    ): void {
        $wrapper = function (...$args) use ($event, $listener, &$wrapper) {
            $this->off($event, $wrapper);
            $listener(...$args);
        };
        $this->on($event, $wrapper);
    }

    /**
     * Remove an event listener.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function off(
        string $event,
        callable $listener
    ): void {
        if (!isset($this->listeners[$event])) {
            return;
        }

        $this->listeners[$event] = array_values(array_filter(
            $this->listeners[$event],
            static function ($l) use ($listener): bool {
                return $l !== $listener;
            }
        ));
    }

    /**
     * Dispatch an event.
     *
     * @param string $event
     * @param mixed ...$args
     * @return void
     */
    public function dispatch(
        string $event,
        mixed ...$args
    ): void {
        if (isset($this->listeners[$event])) {
            foreach ($this->listeners[$event] as $listener) {
                $listener(...$args);
            }
        }

        foreach ($this->listeners as $key => $listeners) {
            if (str_contains($key, '*') && fnmatch($key, $event)) {
                foreach ($listeners as $listener) {
                    if ($key === '*') {
                        $listener($event, ...$args);
                    } else {
                        $listener(...$args);
                    }
                }
            }
        }
    }

    /**
     * Check if an event has listeners.
     *
     * @param string $event
     * @return bool
     */
    public function hasListeners(
        string $event
    ): bool {
        return isset($this->listeners[$event])
            && count($this->listeners[$event])
            > 0;
    }
}
