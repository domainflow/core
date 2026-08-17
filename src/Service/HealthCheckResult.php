<?php

declare(strict_types=1);

namespace DomainFlow\Service;

/**
 * Class HealthCheckResult
 *
 * An immutable health/readiness result: a status plus an optional
 * human-readable reason (e.g. why a provider is degraded or unhealthy).
 */
final readonly class HealthCheckResult
{
    public function __construct(
        public HealthStatus $status,
        public ?string $reason = null
    ) {
    }

    public static function healthy(
        ?string $reason = null
    ): self {
        return new self(HealthStatus::Healthy, $reason);
    }

    public static function degraded(
        ?string $reason = null
    ): self {
        return new self(HealthStatus::Degraded, $reason);
    }

    public static function unhealthy(
        ?string $reason = null
    ): self {
        return new self(HealthStatus::Unhealthy, $reason);
    }
}
