<?php

declare(strict_types=1);

namespace DomainFlow\Service;

/**
 * Interface HealthCheckableInterface
 *
 * Optional contract for a service provider that owns a resource (e.g. a
 * database connection or a queue client) and can report its own
 * readiness/health state. A provider that does not implement this
 * interface is excluded from Application::checkProvidersHealth()'s report
 * entirely, never treated as unhealthy.
 */
interface HealthCheckableInterface
{
    /**
     * Report this provider's current health/readiness state.
     *
     * Called only for a provider that has already been registered (eager,
     * or deferred and already loaded) — never for a deferred provider that
     * has not yet been loaded, which Application::checkProvidersHealth()
     * reports as HealthStatus::NotYetLoaded without calling this method.
     *
     * @return HealthCheckResult
     */
    public function checkHealth(): HealthCheckResult;
}
