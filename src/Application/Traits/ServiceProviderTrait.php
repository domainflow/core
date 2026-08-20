<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\BootstrappingException;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Service\ApplicationHealthReport;
use DomainFlow\Service\HealthCheckableInterface;
use DomainFlow\Service\HealthCheckResult;
use DomainFlow\Service\HealthStatus;
use DomainFlow\Service\OrderedServiceProviderInterface;
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
     * Eager providers accepted by registerProvider(), in call order.
     *
     * Ordered providers registered before boot are kept here without
     * invoking register() until the complete dependency graph is known.
     * Non-ordered providers continue to register immediately, preserving
     * the existing public lifecycle for providers that do not opt in.
     *
     * @var array<string, ServiceProviderInterface>
     */
    protected array $providerCandidates = [];

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
     * registers immediately unless it opts into dependency ordering before
     * boot(); ordered providers register during the topologically sorted
     * boot pass. A deferred provider only registers once one of its provided
     * service identifiers is first requested through get(). After boot, an
     * ordered provider registers and boots immediately only when all declared
     * dependencies remain registered and booted.
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
        if (isset($this->serviceProviders[$class]) || isset($this->providerCandidates[$class])) {
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

        $this->providerCandidates[$class] = $provider;

        if ($provider instanceof OrderedServiceProviderInterface && !$this->booted) {
            return;
        }

        if ($this->booted) {
            try {
                $this->assertProviderDependenciesAreBooted($provider);
                $this->registerProviderOnce($provider);
                $this->bootProviderOnce($provider);
            } catch (Throwable $exception) {
                if (!isset($this->serviceProviders[$class])) {
                    unset($this->providerCandidates[$class]);
                }

                throw $exception;
            }

            return;
        }

        try {
            $this->registerProviderOnce($provider);
        } catch (Throwable $exception) {
            unset($this->providerCandidates[$class]);

            throw $exception;
        }
    }

    /**
     * Ensure a late ordered provider only runs after its declared dependencies.
     *
     * @throws BootstrappingException
     */
    private function assertProviderDependenciesAreBooted(
        ServiceProviderInterface $provider
    ): void {
        if (!($provider instanceof OrderedServiceProviderInterface)) {
            return;
        }

        $providerClass = get_class($provider);
        foreach ($provider->dependsOn() as $dependencyClass) {
            if (!isset($this->serviceProviders[$dependencyClass])
                || !isset($this->bootedProviders[$dependencyClass])
            ) {
                throw BootstrappingException::forUnknownProviderDependency($providerClass, $dependencyClass);
            }
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
            $this->providerCandidates[$providerClass],
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
     * Resolve a registration/boot order for the currently registered
     * providers that respects every OrderedServiceProviderInterface
     * dependsOn() declaration, via a stable topological sort: a provider
     * with no declared dependencies (or that does not implement the
     * interface at all) keeps its plain insertion-order position relative
     * to every other undeclared provider.
     *
     * @throws BootstrappingException on a dependency cycle, or a dependency
     *         on a provider class that was never registered.
     * @return array<string, ServiceProviderInterface>
     */
    protected function orderProvidersForBootstrapping(): array
    {
        $byClass = $this->providerCandidates;
        foreach ($this->serviceProviders as $provider) {
            $class = get_class($provider);
            if (!isset($byClass[$class])) {
                $byClass[$class] = $provider;
            }
        }

        $ordered = [];
        $visiting = [];
        $visited = [];

        foreach (array_keys($byClass) as $class) {
            $this->visitProviderForOrdering($class, $byClass, $visiting, $visited, $ordered);
        }

        return $ordered;
    }

    /**
     * @param array<string, ServiceProviderInterface> $byClass
     * @param array<string, true> $visiting
     * @param array<string, true> $visited
     * @param array<string, ServiceProviderInterface> $ordered
     * @throws BootstrappingException
     * @return void
     */
    private function visitProviderForOrdering(
        string $class,
        array $byClass,
        array &$visiting,
        array &$visited,
        array &$ordered
    ): void {
        if (isset($visited[$class])) {
            return;
        }
        if (isset($visiting[$class])) {
            throw BootstrappingException::forProviderDependencyCycle($class);
        }

        $provider = $byClass[$class];
        $visiting[$class] = true;

        if ($provider instanceof OrderedServiceProviderInterface) {
            foreach ($provider->dependsOn() as $dependencyClass) {
                if (!isset($byClass[$dependencyClass])) {
                    throw BootstrappingException::forUnknownProviderDependency($class, $dependencyClass);
                }
                $this->visitProviderForOrdering($dependencyClass, $byClass, $visiting, $visited, $ordered);
            }
        }

        unset($visiting[$class]);
        $visited[$class] = true;
        $ordered[$class] = $provider;
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

        return parent::get($id);
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

    /**
     * Aggregate the health/readiness of every registered provider that
     * implements HealthCheckableInterface into a single report.
     *
     * A provider that does not implement the interface is excluded from
     * the report entirely, never treated as unhealthy. A deferred provider
     * that has not been loaded yet is reported as HealthStatus::NotYetLoaded
     * — without calling its checkHealth() — and never degrades the overall
     * status, so a provider that may simply never be needed does not read
     * as a failure on a liveness/readiness endpoint wired to this report.
     *
     * @throws Throwable
     * @return ApplicationHealthReport
     */
    public function checkProvidersHealth(): ApplicationHealthReport
    {
        $providers = [];

        foreach ($this->serviceProviders as $provider) {
            if ($provider instanceof HealthCheckableInterface) {
                $providers[get_class($provider)] = $provider->checkHealth();
            }
        }

        foreach ($this->deferredProviderInstances as $class => $provider) {
            if (isset($this->serviceProviders[$class]) || !($provider instanceof HealthCheckableInterface)) {
                continue;
            }
            $providers[$class] = new HealthCheckResult(
                HealthStatus::NotYetLoaded,
                'Deferred provider not yet loaded.'
            );
        }

        return new ApplicationHealthReport($this->aggregateProviderHealthStatus($providers), $providers);
    }

    /**
     * @param array<string, HealthCheckResult> $providers
     * @return HealthStatus
     */
    private function aggregateProviderHealthStatus(
        array $providers
    ): HealthStatus {
        $overall = HealthStatus::Healthy;

        foreach ($providers as $result) {
            if ($result->status === HealthStatus::Unhealthy) {
                return HealthStatus::Unhealthy;
            }
            if ($result->status === HealthStatus::Degraded) {
                $overall = HealthStatus::Degraded;
            }
        }

        return $overall;
    }
}
