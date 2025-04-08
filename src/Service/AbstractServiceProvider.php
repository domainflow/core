<?php

declare(strict_types=1);

namespace DomainFlow\Service;

use DomainFlow\Application;
use Throwable;

/**
 * Class AbstractServiceProvider
 *
 * A base implementation of ServiceProviderInterface. Subclasses must implement
 * the register() method while boot() and provides() have default implementations.
 */
abstract class AbstractServiceProvider implements ServiceProviderInterface
{
    /**
     * Indicates whether this provider is deferred.
     *
     * @var bool
     */
    public bool $defer = false;

    /**
     * The list of service keys provided by this provider.
     *
     * @var list<string>
     */
    protected array $providedServices = [];

    /**
     * Register services into the application container.
     *
     * @param Application $app
     * @throws Throwable
     * @return void
     */
    abstract public function register(
        Application $app
    ): void;

    /**
     * Boot the service provider.
     *
     * @param Application $app
     * @return void
     */
    public function boot(
        Application $app
    ): void {
        // Default: No boot actions.
    }

    /**
     * Get the list of service keys provided by this provider.
     *
     * @return list<string>
     */
    public function provides(): array
    {
        return $this->providedServices;
    }
}
