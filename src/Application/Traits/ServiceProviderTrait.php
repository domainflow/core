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
     * Entries are removed once the identifier has been resolved. For
     * collision detection across a service key's whole lifetime, see
     * $deferredServiceClaims.
     *
     * @var array<string, string>
     */
    protected array $deferredServices = [];

    /**
     * Permanent record of which provider class first claimed a deferred
     * service identifier (class-string, interface-string, or plain string
     * alias — all are opaque identifiers here). Unlike $deferredServices,
     * an entry is never removed on resolution, only on unregisterProvider().
     * This is what registerProvider() checks to reject a second, different
     * provider class claiming an already-claimed identifier.
     *
     * Format: [ service key => provider class name ]
     *
     * @var array<string, string>
     */
    protected array $deferredServiceClaims = [];

    /**
     * The exact instance passed to registerProvider() for each deferred
     * provider class, keyed by class name. Deferred providers are resolved
     * from this stored instance rather than being re-instantiated, so a
     * provider with required constructor dependencies works the same way
     * whether it is eager or deferred: the caller constructs it once, and
     * that instance is the one register()/boot() are eventually called on.
     *
     * Format: [ provider class name => ServiceProviderInterface instance ]
     *
     * @var array<string, ServiceProviderInterface>
     */
    protected array $deferredProviderInstances = [];

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
     * A deferred provider's provided identifiers (class, interface, or plain
     * string) must each be claimed by exactly one provider class. Claiming
     * an identifier already owned by a different provider class throws
     * instead of silently discarding the earlier claim. Registering the
     * same provider class again for the same identifier is not a collision;
     * the most recently supplied instance is the one used on resolution,
     * which keeps constructor-dependent providers re-registrable.
     *
     * @param ServiceProviderInterface $provider
     * @throws BootstrappingException|Throwable
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
            foreach ($provider->provides() as $serviceKey) {
                $existingClaim = $this->deferredServiceClaims[$serviceKey] ?? null;

                if ($existingClaim !== null && $existingClaim !== $class) {
                    throw BootstrappingException::forDeferredServiceIdentifierCollision(
                        $serviceKey,
                        $existingClaim,
                        $class
                    );
                }
            }

            // Only claim identifiers once every one of them has passed the
            // collision check above, so a rejected registration leaves no
            // partial claims behind.
            foreach ($provider->provides() as $serviceKey) {
                $this->deferredServiceClaims[$serviceKey] = $class;
                $this->deferredServices[$serviceKey] = $class;
            }

            // Store the actual instance (which may carry constructor
            // dependencies) so it can be resolved without re-instantiation.
            $this->deferredProviderInstances[$class] = $provider;

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
     * Also releases any deferred service identifiers this provider class
     * had claimed, making them available for a different provider class to
     * claim afterward.
     *
     * @param string $providerClass The fully-qualified class name of the provider.
     * @throws EventManagerException
     * @return void
     */
    public function unregisterProvider(
        string $providerClass
    ): void {
        unset(
            $this->serviceProviders[$providerClass],
            $this->bootedProviders[$providerClass],
            $this->deferredProviderInstances[$providerClass]
        );

        foreach ($this->deferredServices as $serviceKey => $storedProvider) {
            if ($storedProvider === $providerClass) {
                unset($this->deferredServices[$serviceKey]);
            }
        }

        foreach ($this->deferredServiceClaims as $serviceKey => $storedProvider) {
            if ($storedProvider === $providerClass) {
                unset($this->deferredServiceClaims[$serviceKey]);
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
     * Pre-warm every still-deferred provider by registering and booting it
     * immediately, for services that have not already been bound.
     *
     * This is an explicit opt-in utility (e.g. for a CLI warm-up step) and
     * is never called automatically by boot() or get(): the documented
     * lifecycle only loads a Deferred provider on first request of one of
     * its provided service identifiers (see docs/ARCHITECTURE.md). Calling
     * this defeats that laziness for whatever providers are still deferred
     * at the time it runs.
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
     * Resolves against the instance stored in $deferredProviderInstances at
     * registerProvider() time rather than constructing a new one, so a
     * provider with required constructor dependencies resolves correctly.
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
            $provider = $this->deferredProviderInstances[$providerClass] ?? null;

            if ($provider === null) {
                throw BootstrappingException::forMissingDeferredProviderInstance($serviceKey, $providerClass);
            }

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
