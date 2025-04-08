<?php

declare(strict_types=1);

namespace DomainFlow\Service;

use DomainFlow\Application;
use Throwable;

/**
 * Interface ServiceProviderInterface
 *
 * A contract for service providers to register and boot services within the Application.
 */
interface ServiceProviderInterface
{
    /**
     * Register services into the application container.
     *
     * @param Application $app
     * @throws Throwable
     * @return void
     */
    public function register(Application $app): void;

    /**
     * Boot the service provider.
     *
     * @param Application $app
     * @throws Throwable
     * @return void
     */
    public function boot(Application $app): void;

    /**
     * Get the list of service keys that this provider offers.
     *
     * @return list<string>
     */
    public function provides(): array;

    /**
     * Determine if the provider is deferred.
     *
     * @return bool
     */
    public function isDeferred(): bool;
}
