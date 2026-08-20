<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Application\Interface\SystemEventStoreInterface;
use Throwable;

trait EventManagerTrait
{
    protected const string EVENT_EVENT_MANAGER_DISPATCH_ERROR_KEY = 'event_manager.dispatch.error';
    protected const string EVENT_EVENT_MANAGER_DISPATCHER_SET_KEY = 'event_manager.dispatcher.set';

    protected EventDispatcherInterface $eventDispatcher;

    protected SystemEventStoreInterface $eventStore;

    /**
     * Set the event store.
     *
     * @param SystemEventStoreInterface $eventStore
     * @return void
     */
    public function setEventStore(
        SystemEventStoreInterface $eventStore
    ): void {
        $this->eventStore = $eventStore;
    }

    /**
     * Set the event dispatcher and fire an event indicating the dispatcher was set.
     *
     * May be called at any time, including after boot(): EventDispatcherInterface
     * resolves through the container as a live binding to the Application's
     * current dispatcher, so a later container resolution always reflects the
     * dispatcher set here, not a stale boot-time snapshot.
     *
     * @param EventDispatcherInterface $dispatcher
     * @throws EventManagerException
     * @return void
     */
    public function setEventDispatcher(
        EventDispatcherInterface $dispatcher
    ): void {
        $this->eventDispatcher = $dispatcher;
        $this->fireEvent(self::EVENT_EVENT_MANAGER_DISPATCHER_SET_KEY, get_class($dispatcher));
    }

    /**
     * Get the currently active event dispatcher.
     *
     * @return EventDispatcherInterface
     */
    public function getEventDispatcher(): EventDispatcherInterface
    {
        return $this->eventDispatcher;
    }

    /**
     * Register an event listener for a given event.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function on(
        string $event,
        callable $listener
    ): void {
        $this->eventDispatcher->on($event, $listener);
    }

    /**
     * Register a one-time event listener for a given event.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function once(
        string $event,
        callable $listener
    ): void {
        $this->eventDispatcher->once($event, $listener);
    }

    /**
     * Unregister an event listener for a given event.
     *
     * @param string $event
     * @param callable $listener
     * @return void
     */
    public function off(
        string $event,
        callable $listener
    ): void {
        $this->eventDispatcher->off($event, $listener);
    }

    /**
     * Fire an event, calling all registered listeners (including wildcard listeners).
     * If an error occurs during dispatch, an error event is fired and an EventManagerException is thrown.
     *
     * @param string $event
     * @param mixed ...$args
     * @throws EventManagerException
     * @return void
     */
    public function fireEvent(
        string $event,
        mixed ...$args
    ): void {
        try {
            $this->eventDispatcher->dispatch($event, ...$args);
            $this->storeEvent($event, ...$args);
        } catch (Throwable $e) {
            // Avoid recursive errors if the error event itself fails.
            if ($event !== self::EVENT_EVENT_MANAGER_DISPATCH_ERROR_KEY) {
                try {
                    $this->eventDispatcher->dispatch(self::EVENT_EVENT_MANAGER_DISPATCH_ERROR_KEY, $event, $e);
                } catch (Throwable) {
                    // Diagnostic listeners must never replace the primary
                    // dispatch failure seen by the caller.
                }
            }
            throw EventManagerException::forDispatchFailure($event, $e);
        }
    }

    /**
     * Determine if there are listeners registered for a given event.
     *
     * @param string $event
     * @return bool
     */
    public function hasListeners(
        string $event
    ): bool {
        return $this->eventDispatcher->hasListeners($event);
    }

    /**
     * Store an event and its arguments for later retrieval using the SystemEventStore.
     *
     * @param string $event
     * @param mixed ...$args
     * @return void
     */
    protected function storeEvent(
        string $event,
        mixed ...$args
    ): void {
        $this->eventStore->addEvent($event, $args);
    }

    /**
     * Get all stored events.
     *
     * @return array<string, array<int, array{order: int, timestamp: float, args: array<mixed>}>>
     */
    public function getEvents(): array
    {
        return $this->eventStore->getEvents();
    }

}
