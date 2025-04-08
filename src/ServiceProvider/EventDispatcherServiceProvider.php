<?php

declare(strict_types=1);

namespace DomainFlow\ServiceProvider;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Service\AbstractServiceProvider;

class EventDispatcherServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [
        EventDispatcherInterface::class,
    ];

    public bool $defer = false;

    /**
     * Register the event dispatcher.
     *
     * @param Application $app
     * @return void
     */
    public function register(
        Application $app
    ): void {
        // Register the basic event dispatcher.
        $app->instance(
            EventDispatcherInterface::class,
            new BasicEventDispatcher()
        );
    }

    /**
     * Boot the service provider.
     *
     * @param Application $app
     * @return void
     */
    public function boot(
        Application $app
    ): void {
        // Boot actions if needed.
    }

    /**
     * Get status of deferred loading.
     *
     * @return bool
     */
    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
