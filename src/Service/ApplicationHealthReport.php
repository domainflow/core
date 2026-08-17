<?php

declare(strict_types=1);

namespace DomainFlow\Service;

/**
 * Class ApplicationHealthReport
 *
 * The aggregate result of Application::checkProvidersHealth(): an overall
 * status derived from every registered HealthCheckableInterface provider
 * (a HealthStatus::NotYetLoaded deferred provider never degrades the
 * overall status), plus each provider's individual result keyed by class.
 */
final readonly class ApplicationHealthReport
{
    /**
     * @param HealthStatus $overallStatus
     * @param array<string, HealthCheckResult> $providers
     */
    public function __construct(
        public HealthStatus $overallStatus,
        public array $providers
    ) {
    }

    public function isHealthy(): bool
    {
        return $this->overallStatus === HealthStatus::Healthy;
    }
}
