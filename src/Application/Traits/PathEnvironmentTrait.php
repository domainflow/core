<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Enum\EnvironmentEnum;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;

trait PathEnvironmentTrait
{
    protected const string EVENT_PATH_BASE_SET_KEY = 'path.base.set';
    protected const string EVENT_PATH_BASE_ERROR_KEY = 'path.base.error';
    protected const string EVENT_PATH_CONFIG_SET_KEY = 'path.config.set';
    protected const string EVENT_PATH_CONFIG_ERROR_KEY = 'path.config.error';
    protected const string EVENT_PATH_ENVIRONMENT_SET_KEY = 'path.environment.set';

    /**
     * The base path of the application.
     *
     * @var string
     */
    protected string $basePath;

    /**
     * The path to configuration files.
     *
     * @var string
     */
    protected string $configPath;

    /**
     * The current environment (e.g., "production", "development").
     *
     * @var EnvironmentEnum
     */
    protected EnvironmentEnum $environment = EnvironmentEnum::ENVIRONMENT_PRODUCTION;

    /**
     * Check if the current environment matches one or more given environments.
     *
     * @param EnvironmentEnum $environment
     * @return bool
     */
    public function isEnvironment(
        EnvironmentEnum $environment
    ): bool {
        return $this->environment === $environment;

    }

    /**
     * Set the base path for the application.
     *
     * @param string $path
     * @throws EventManagerException
     * @throws PathEnvironmentException
     * @return static
     */
    public function setBasePath(
        string $path
    ): static {
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);
        if (!is_dir($trimmed)) {
            $this->fireEvent(self::EVENT_PATH_BASE_ERROR_KEY, $trimmed);
            throw PathEnvironmentException::forInvalidBasePath($trimmed);
        }
        $this->basePath = $trimmed;
        $this->fireEvent(self::EVENT_PATH_BASE_SET_KEY, $this->basePath);

        return $this;
    }

    /**
     * Get the base path.
     *
     * @param string $subPath
     * @return string
     */
    public function basePath(
        string $subPath = ''
    ): string {
        return $subPath !== ''
            ? $this->basePath . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR)
            : $this->basePath;
    }

    /**
     * Set the configuration path.
     *
     * @param string $path
     * @throws EventManagerException|PathEnvironmentException
     * @return static
     */
    public function setConfigPath(
        string $path
    ): static {
        $trimmed = rtrim($path, DIRECTORY_SEPARATOR);
        if (!is_dir($trimmed)) {
            $this->fireEvent(self::EVENT_PATH_CONFIG_ERROR_KEY, $trimmed);
            throw PathEnvironmentException::forInvalidConfigPath($trimmed);
        }
        $this->configPath = $trimmed;
        $this->fireEvent(self::EVENT_PATH_CONFIG_SET_KEY, $this->configPath);

        return $this;
    }

    /**
     * Get the configuration path.
     *
     * @param string $subPath
     * @return string
     */
    public function configPath(
        string $subPath = ''
    ): string {
        return $subPath !== ''
            ? $this->configPath . DIRECTORY_SEPARATOR . ltrim($subPath, DIRECTORY_SEPARATOR)
            : $this->configPath;
    }

    /**
     * Get the current environment.
     *
     * @return EnvironmentEnum
     */
    public function environment(): EnvironmentEnum
    {
        return $this->environment;
    }

    /**
     * Set the current environment.
     *
     * @param EnvironmentEnum $environment
     * @throws EventManagerException
     * @return static
     */
    public function setEnvironment(
        EnvironmentEnum $environment
    ): static {
        $this->environment = $environment;
        $this->fireEvent(self::EVENT_PATH_ENVIRONMENT_SET_KEY, $this->environment);

        return $this;
    }
}
