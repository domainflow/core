<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\TerminationException;
use Throwable;

/**
 * Trait TerminationTrait
 *
 * Provides a mechanism for registering and executing termination callbacks.
 */
trait TerminationTrait
{
    protected const string EVENT_TERMINATING_KEY = 'termination.init';
    protected const string EVENT_TERMINATED_KEY = 'termination.complete';
    protected const string EVENT_TERMINATION_ERROR_KEY = 'termination.error';

    /**
     * Termination callbacks to be executed during application shutdown.
     *
     * @var list<callable(self):void>
     */
    protected array $terminationCallbacks = [];

    /**
     * Register a callback to be executed when the application terminates.
     *
     * @param callable(self):void $callback
     * @return void
     */
    public function registerTerminationCallback(
        callable $callback
    ): void {
        $this->terminationCallbacks[] = $callback;
    }

    /**
     * Terminate the application, executing registered termination callbacks.
     *
     * @throws TerminationException|EventManagerException
     * @return void
     */
    public function terminate(): void
    {
        $this->fireEvent(self::EVENT_TERMINATING_KEY, $this);

        // Execute each termination callback.
        foreach ($this->terminationCallbacks as $callback) {
            try {
                $callback($this);
            } catch (Throwable $e) {
                $this->fireEvent(self::EVENT_TERMINATION_ERROR_KEY, $e);
                throw TerminationException::forCallbackFailure($e);
            }
        }

        $this->fireEvent(self::EVENT_TERMINATED_KEY, $this);
    }
}
