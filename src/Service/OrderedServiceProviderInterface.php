<?php

declare(strict_types=1);

namespace DomainFlow\Service;

/**
 * Interface OrderedServiceProviderInterface
 *
 * Opt-in extension of ServiceProviderInterface for a provider that must be
 * registered and booted only after one or more other named provider
 * classes, within the same boot() cycle. A provider that does not implement
 * this interface is treated as having no declared dependencies and keeps
 * its plain insertion-order position.
 */
interface OrderedServiceProviderInterface extends ServiceProviderInterface
{
    /**
     * Provider classes that must be registered and booted before this one.
     *
     * Each entry must be the class of a provider registered in the same
     * boot() cycle; a class that was never registered makes boot() throw a
     * BootstrappingException rather than silently ignoring the dependency.
     *
     * @return list<class-string<ServiceProviderInterface>>
     */
    public function dependsOn(): array;
}
