<?php

declare(strict_types=1);

namespace DomainFlow\ServiceProvider;

use DomainFlow\Application;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Service\AbstractServiceProvider;

class EventDispatcherServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [
        EventDispatcherInterface::class,
    ];

    public bool $defer = false;

    /**
     * Bind the Application's own event dispatcher instance as
     * EventDispatcherInterface, so lifecycle listeners registered via
     * Application::on()/once() and services resolved through the container
     * observe the same event stream instead of two independent dispatchers.
     *
     * @param Application $app
     * @return void
     */
    public function register(
        Application $app
    ): void {
        $app->instance(
            EventDispatcherInterface::class,
            $app->getEventDispatcher()
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
