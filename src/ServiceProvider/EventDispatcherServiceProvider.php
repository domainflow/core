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
     * Bind EventDispatcherInterface to a non-shared closure that always
     * resolves the Application's *current* event dispatcher, so lifecycle
     * listeners registered via Application::on()/once() and services
     * resolved through the container observe the same event stream instead
     * of two independent dispatchers — even across a post-boot
     * setEventDispatcher() swap, which a one-time instance() snapshot at
     * register() time would miss.
     *
     * @param Application $app
     * @return void
     */
    public function register(
        Application $app
    ): void {
        $app->bind(
            EventDispatcherInterface::class,
            static fn (): EventDispatcherInterface => $app->getEventDispatcher(),
            shared: false
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
}
