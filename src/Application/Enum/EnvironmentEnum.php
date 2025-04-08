<?php

declare(strict_types=1);

namespace DomainFlow\Application\Enum;

/**
 * EnvironmentEnum
 *
 * Enumerates the possible environment values.
 */
enum EnvironmentEnum: string
{
    case ENVIRONMENT_PRODUCTION = 'production';
    case ENVIRONMENT_DEVELOPMENT = 'development';
    case ENVIRONMENT_STAGING = 'staging';
    case ENVIRONMENT_TESTING = 'testing';
    case ENVIRONMENT_CUSTOM = 'custom';

    /**
     * Create an EnvironmentEnum from a string.
     *
     * @param string $env
     * @return EnvironmentEnum
     */
    public static function fromString(
        string $env
    ): self {
        return self::tryFrom(strtolower($env))
            ?? self::ENVIRONMENT_CUSTOM;
    }

    /**
     * Convert the EnvironmentEnum to a string.
     *
     * @return string
     */
    public function toString(): string
    {
        return $this->value;
    }
}
