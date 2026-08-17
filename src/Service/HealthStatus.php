<?php

declare(strict_types=1);

namespace DomainFlow\Service;

/**
 * Enum HealthStatus
 *
 * The health/readiness state a HealthCheckableInterface provider reports.
 * NotYetLoaded is reserved for Application::checkProvidersHealth()'s own
 * aggregation of a deferred provider that has not been loaded yet; a
 * provider's own checkHealth() implementation should only ever return
 * Healthy, Degraded, or Unhealthy.
 */
enum HealthStatus: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case Unhealthy = 'unhealthy';
    case NotYetLoaded = 'not_yet_loaded';
}
