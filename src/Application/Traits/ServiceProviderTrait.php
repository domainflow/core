<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\BootstrappingException;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Service\ServiceProviderInterface;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

/**
 * Trait ServiceProviderTrait
 *
 * Provides methods for registering, unregistering, and loading service providers.
 */
trait ServiceProviderTrait
{
    protected const string EVENT_PROVIDER_REGISTERED_KEY = 'service_provider.registered';
    protected const string EVENT_PROVIDER_UNREGISTERED_KEY = 'service_provider.unregistered';
    protected const string EVENT_PROVIDER_DEFERRED_LOADED_KEY = 'service_provider.deferred.loaded';
    protected const string EVENT_PROVIDER_DEFERRED_REMOVED_KEY = 'service_provider.deferred.removed';

    /**
     * Registered service providers.
     *
     * Format: [ provider class name => ServiceProviderInterface instance ]
     *
     * @var array<string, ServiceProviderInterface>
     */
    protected array $serviceProviders = [];

    /**
     * Deferred services mapping.
     *
     * Format: [ service key => provider class name ]
     *
     * @var array<string, string>
     */
    protected array $deferredServices = [];

    /**
     * Provider classes whose boot() has already run.
     *
     * Format: [ provider class name => true ]
     *
     * @var array<string, true>
     */
    protected array $bootedProviders = [];

    /**
     * Register a service provider with the application.
     *
     * A given provider class is registered at most once. An eager provider
     * registers immediately; a deferred provider only registers once one of
     * its provided service identifiers is first requested through get().
     *
     * @param ServiceProviderInterface $provider
     * @throws Throwable
     * @return void
     */
    public function registerProvider(
        ServiceProviderInterface $provider
    ): void {
        $class = get_class($provider);

        // Prevent duplicate registrations
        if (isset($this->serviceProviders[$class])) {
            return;
        }

        if ($provider->isDeferred() === true) {
            // Store in deferred services but do NOT register
            foreach ($provider->provides() as $serviceKey) {
                $this->deferredServices[$serviceKey] = $class;
            }

            return;
        }

        $this->registerProviderOnce($provider);

        if ($this->booted) {
            $this->bootProviderOnce($provider);
        }
    }

    /**
     * Unregister a previously registered service provider.
     *
     * @param string $providerClass The fully-qualified class name of the provider.
     * @throws EventManagerException
     * @return void
     */
    public function unregisterProvider(
        string $providerClass
    ): void {
        unset($this->serviceProviders[$providerClass], $this->bootedProviders[$providerClass]);

        // Remove deferred services tied to this provider
        foreach ($this->deferredServices as $serviceKey => $storedProvider) {
            if ($storedProvider === $providerClass) {
                unset($this->deferredServices[$serviceKey]);
            }
        }

        $this->fireEvent(self::EVENT_PROVIDER_UNREGISTERED_KEY, $providerClass);
    }

    /**
     * Call register() on a provider exactly once for its class.
     *
     * @throws Throwable
     * @return void
     */
    private function registerProviderOnce(
        ServiceProviderInterface $provider
    ): void {
        $class = get_class($provider);

        if (isset($this->serviceProviders[$class])) {
            return;
        }

        $provider->register($this);
        $this->serviceProviders[$class] = $provider;
        $this->fireEvent(self::EVENT_PROVIDER_REGISTERED_KEY, $class);
    }

    /**
     * Call boot() on a registered provider exactly once for its class.
     *
     * @throws Throwable
     * @return void
     */
    private function bootProviderOnce(
        ServiceProviderInterface $provider
    ): void {
        $class = get_class($provider);

        if (!isset($this->serviceProviders[$class]) || isset($this->bootedProviders[$class])) {
            return;
        }

        $provider->boot($this);
        $this->bootedProviders[$class] = true;
    }

    /**
     * Get the list of registered service providers.
     *
     * @return array<string, ServiceProviderInterface>
     */
    public function getProviders(): array
    {
        return $this->serviceProviders;
    }

    /**
     * Load any deferred service providers for services that have not been bound.
     *
     * @throws Throwable
     * @return void
     */
    public function loadDeferredProviders(): void
    {
        foreach ($this->deferredServices as $serviceKey => $providerClass) {
            if (!$this->has($serviceKey)) {
                $this->resolveDeferredProvider($serviceKey, $providerClass);
            }
        }
    }

    /**
     * Register and boot the provider for a deferred service key exactly
     * once, then remove that key from the deferred map.
     *
     * @param string $serviceKey
     * @param string $providerClass
     * @throws BootstrappingException|Throwable
     * @return void
     */
    private function resolveDeferredProvider(
        string $serviceKey,
        string $providerClass
    ): void {
        if (!isset($this->serviceProviders[$providerClass])) {
            /** @var ServiceProviderInterface $provider */
            $provider = new $providerClass();

            try {
                $this->registerProviderOnce($provider);
                $this->bootProviderOnce($provider);
            } catch (Throwable $e) {
                throw BootstrappingException::forDeferredProviderLoadError($serviceKey, $providerClass, $e);
            }
        }

        $this->fireEvent(self::EVENT_PROVIDER_DEFERRED_LOADED_KEY, $serviceKey, $providerClass);

        unset($this->deferredServices[$serviceKey]);

        $this->fireEvent(self::EVENT_PROVIDER_DEFERRED_REMOVED_KEY, $serviceKey, $providerClass);
    }

    /**
     * Retrieve an entry from the container.
     *
     * @param string $id
     * @throws NotFoundExceptionInterface|ContainerExceptionInterface|Throwable
     * @return mixed
     */
    public function get(
        string $id
    ): mixed {
        if (isset($this->deferredServices[$id])) {
            $this->resolveDeferredProvider($id, $this->deferredServices[$id]);
        }

        $value = parent::get($id);

        // domainflow/container no longer caches resolved services as a side
        // effect of make()/get(); Application still exposes a persistable
        // resolved-services snapshot, so it must populate it explicitly.
        // @phpstan-ignore method.deprecated
        $this->cacheResolvedService($id, $value);

        return $value;
    }

    /**
     * Determine if a service provider is already registered.
     *
     * @param string $providerClass
     * @return bool
     */
    public function hasProvider(
        string $providerClass
    ): bool {
        return isset($this->serviceProviders[$providerClass]);
    }
}
