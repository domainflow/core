<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\BootstrappingException;
use DomainFlow\Application\Exception\CacheException;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Container\Exception\ContainerException;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

trait BootstrappingTrait
{
    private const string CACHE_FILE_KEY = 'cache/services.cache';
    protected const string EVENT_BOOTING_KEY = 'booting.init';
    protected const string EVENT_BOOTED_KEY = 'booting.complete';
    protected const string EVENT_BOOTING_ERROR_KEY = 'booting.error';

    /**
     * Flag indicating whether the application has booted.
     *
     * @var bool
     */
    protected bool $booted = false;

    /**
     * Callbacks to run before booting.
     *
     * @var list<callable(self):void>
     */
    protected array $bootingCallbacks = [];

    /**
     * Callbacks to run after booting.
     *
     * @var list<callable(self):void>
     */
    protected array $bootedCallbacks = [];

    /**
     * List of service classes for attribute-based auto-registration.
     *
     * @var array<class-string>
     */
    protected array $attributeServiceClasses = [];

    /**
     * List of listener instances for attribute-based auto-registration.
     *
     * @var array<object>
     */
    protected array $attributeListenerInstances = [];

    /**
     * Register a callback to be executed before the application boots.
     *
     * @param callable(self):void $callback
     * @return void
     */
    public function booting(
        callable $callback
    ): void {
        $this->bootingCallbacks[] = $callback;
    }

    /**
     * Register a callback to be executed after the application boots.
     *
     * @param callable(self):void $callback
     * @return void
     */
    public function booted(
        callable $callback
    ): void {
        $this->bootedCallbacks[] = $callback;
    }

    /**
     * Return the boot status of the application.
     *
     * @return bool
     */
    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Boot the application.
     *
     * Executes booting callbacks, registers and boots all service providers,
     * loads deferred providers, then executes booted callbacks.
     *
     * @throws Throwable
     * @return void
     */
    public function boot(): void
    {
        if (!isset(self::$container_instances[static::class])) {
            static::setInstance($this);
        }

        if ($this->bootFromCache()) {
            return;
        }

        $this->fireEvent(self::EVENT_BOOTING_KEY, $this);

        if ($this->booted) {
            return;
        }

        try {
            $this->runBootingCallbacks();
            $this->applyAttributeRegistrations();
            $this->registerDefaultServiceProviders();
            $this->registerAndBootProviders();
            $this->runBootedCallbacks();
            $this->booted = true;
            $this->fireEvent(self::EVENT_BOOTED_KEY, $this);
        } catch (Throwable $e) {
            $this->fireEvent(self::EVENT_BOOTING_ERROR_KEY, 'Generic boot error', $e);
            throw BootstrappingException::forGenericError('An error occurred during bootstrapping', $e);
        }
    }

    /**
     * Load deferred service providers and cache resolved services.
     *
     * @throws ContainerExceptionInterface|NotFoundExceptionInterface|Throwable
     */
    public function resolveDeferredServices(): void
    {
        foreach ($this->serviceProviders as $provider) {
            if (!property_exists($provider, 'defer') || !$provider->defer) {
                foreach ($provider->provides() as $serviceKey) {
                    if (!$this->has($serviceKey)) {
                        $instance = $this->get($serviceKey);
                        $this->resolvedServicesCache[$serviceKey] = $instance;
                    }
                }
            }
        }
    }

    /**
     * Check for a cached services file and load if available.
     *
     * @throws EventManagerException|CacheException
     * @return bool
     */
    private function bootFromCache(): bool
    {
        $cacheFile = $this->basePath(self::CACHE_FILE_KEY);
        if ($this->isCachingEnabled() && file_exists($cacheFile)) {
            $this->loadResolvedServicesFromFile($cacheFile);
            $this->fireEvent(self::EVENT_BOOTED_KEY, $this);
            $this->booted = true;

            return true;
        }

        return false;
    }

    /**
     * Execute booting callbacks.
     *
     * @throws Throwable
     * @return void
     */
    private function runBootingCallbacks(): void
    {
        foreach ($this->bootingCallbacks as $callback) {
            try {
                $callback($this);
            } catch (Throwable $e) {
                $this->fireEvent(self::EVENT_BOOTING_ERROR_KEY, 'Booting callback error', $e);
                throw BootstrappingException::forBootCallbackFailure('A booting callback failed', $e);
            }
        }
    }

    /**
     * Register default service providers.
     *
     * @throws Throwable
     * @return void
     */
    private function registerDefaultServiceProviders(): void
    {
        $defaultProviders = [
            new EventDispatcherServiceProvider(),
        ];
        foreach ($defaultProviders as $provider) {
            $this->registerProvider($provider);
        }
    }

    /**
     * Register and boot all service providers.
     *
     * @throws Throwable
     * @return void
     */
    private function registerAndBootProviders(): void
    {
        foreach ($this->serviceProviders as $provider) {
            try {
                $provider->register($this);
            } catch (Throwable $e) {
                $this->fireEvent(self::EVENT_BOOTING_ERROR_KEY, 'Provider registration error', $e);
                throw BootstrappingException::forProviderRegistrationFailure(get_class($provider), $e);
            }
        }
        foreach ($this->serviceProviders as $provider) {
            $provider->boot($this);
        }
    }

    /**
     * Execute booted callbacks.
     *
     * @throws Throwable
     * @return void
     */
    private function runBootedCallbacks(): void
    {
        foreach ($this->bootedCallbacks as $callback) {
            try {
                $callback($this);
            } catch (Throwable $e) {
                $this->fireEvent(self::EVENT_BOOTING_ERROR_KEY, 'Booted callback error', $e);
                throw BootstrappingException::forBootCallbackFailure('A booted callback failed', $e);
            }
        }
    }

    /**
     * Apply attribute-based registrations.
     * This method assumes the Application also uses AttributeRegistrarTrait.
     *
     * @throws ContainerException
     * @return void
     */
    protected function applyAttributeRegistrations(): void
    {
        if (!empty($this->attributeServiceClasses)) {
            $this->autoRegisterServices($this->attributeServiceClasses);
        }
        if (!empty($this->attributeListenerInstances)) {
            $this->autoRegisterEventListeners($this->attributeListenerInstances);
        }
    }
}
