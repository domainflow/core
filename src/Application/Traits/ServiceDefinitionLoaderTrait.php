<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use Closure;
use DomainFlow\Application\Class\FileContainerCache;
use DomainFlow\Application\Class\FileReader;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\ServiceDefinitionLoaderException;
use DomainFlow\Container\Cache\ContainerCacheInterface;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Trait ServiceDefinitionLoaderTrait
 *
 * Provides methods to load service definitions from configuration files.
 *
 * Supported file formats:
 * - PHP: returns an array of definitions
 * - JSON
 * - YAML (requires Symfony YAML)
 *
 * Example configuration structure:
 * [
 *     'MyService' => [
 *         'concrete' => '\\My\\Namespace\\MyService', // or a fully-qualified class string
 *         'shared' => true, // optional, default false
 *         'tags' => ['someTag'], // optional array of tags
 *     ],
 *     'MyOtherService' => [
 *         // If you prefer to use a closure or factory function,
 *         // define a 'factory' key instead of 'concrete'.
 *         'factory' => function ($container) {
 *             return new \\My\\Namespace\\MyOtherService(
 *                 $container->get('MyService')
 *             );
 *         },
 *         'shared' => true,
 *     ],
 * ]
 *
 * Usage:
 * $app->loadServiceDefinitions('/path/to/services.php');
 * // or .json, .yaml, etc.
 *
 * This trait will bind each service to the container.
 * - If 'factory' is provided and is callable, it takes precedence over 'concrete'.
 * - Otherwise, 'concrete' is used as the class or alias to bind.
 * - If 'shared' is true, the service becomes a singleton.
 * - 'tags' can be used to group services for later retrieval or processing.
 */
trait ServiceDefinitionLoaderTrait
{
    protected const string EVENT_SERVICE_DEFINITION_FILE_PARSED_KEY = 'service_definition.file.parsed';
    protected const string EVENT_SERVICE_DEFINITION_BOUND_KEY = 'service_definition.bound';
    protected const string EVENT_SERVICE_DEFINITION_ERROR_KEY = 'service_definition.error';

    protected ?FileReader $fileReader = null;

    /**
     * Set a custom file reader (useful for testing).
     *
     * @param FileReader $fileReader
     * @return void
     */
    public function setFileReader(
        FileReader $fileReader
    ): void {
        $this->fileReader = $fileReader;
    }

    /**
     * Get the file reader; if not set, instantiate a default one.
     *
     * @return FileReader
     */
    protected function getFileReader(): FileReader
    {
        if ($this->fileReader === null) {
            $this->fileReader = new FileReader();
        }

        return $this->fileReader;
    }

    /**
     * Load service definitions from a configuration file.
     *
     * Supports PHP, JSON, and YAML (if Symfony YAML component is installed).
     *
     * A FileContainerCache set via setExternalCache() tracks $file as a
     * resource for the container's declarative-definitions cache entry: a
     * later cache read self-invalidates once $file's mtime advances past
     * what was recorded when this call last persisted it, so a changed
     * service-definition file is never served from a stale cache.
     *
     * @param string $file
     * @throws RuntimeException
     * @throws ServiceDefinitionLoaderException
     * @throws EventManagerException
     * @return void
     */
    public function loadServiceDefinitions(
        string $file
    ): void {
        if (!file_exists($file)) {
            throw new RuntimeException("Service definition file not found: $file");
        }
        if ($this->externalCache instanceof FileContainerCache) {
            $this->externalCache->trackResource(ContainerCacheInterface::DEFINITION_CACHE_KEY, $file);
        }
        $definitions = $this->parseDefinitionsFile($file);
        $this->fireEvent(self::EVENT_SERVICE_DEFINITION_FILE_PARSED_KEY, $file);
        if (!is_array($definitions)) {
            throw new RuntimeException("Invalid service definitions format in file: $file");
        }
        foreach ($definitions as $abstract => $definition) {
            /** @var array<string, mixed> $definition */
            if (!is_array($definition)) {
                $this->fireEvent(self::EVENT_SERVICE_DEFINITION_ERROR_KEY, $abstract, "Definition is not an array");
                throw ServiceDefinitionLoaderException::forInvalidDefinition($abstract, "Definition is not an array");
            }
            $this->processServiceDefinition($abstract, $definition);
        }
    }

    /**
     * Parse a definitions file based on its extension.
     *
     * @param string $file
     * @throws RuntimeException
     * @return mixed
     */
    protected function parseDefinitionsFile(
        string $file
    ): mixed {
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($ext === 'php') {
            return include $file;
        } elseif ($ext === 'json') {
            $content = $this->getFileReader()->read($file);
            if ($content === false) {
                throw new RuntimeException("Failed to read JSON service definition file: $file");
            }
            $data = json_decode($content, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new RuntimeException("JSON decode error in file: $file. Error: " . json_last_error_msg());
            }

            return $data;
        } elseif ($ext === 'yaml' || $ext === 'yml') {
            $content = $this->getFileReader()->read($file);
            if ($content === false) {
                throw new RuntimeException("Failed to read YAML service definition file: $file");
            }

            return Yaml::parse($content);
        } else {
            throw new RuntimeException("Unsupported file extension: $ext");
        }
    }

    /**
     * Process an individual service definition.
     *
     * @param string $abstract
     * @param array<string, mixed> $definition
     * @throws ServiceDefinitionLoaderException|EventManagerException
     * @return void
     */
    protected function processServiceDefinition(
        string $abstract,
        array $definition
    ): void {
        try {
            if (isset($definition['factory']) && is_callable($definition['factory'])) {
                // Ensure the factory is a Closure.
                $factory = $definition['factory'] instanceof Closure
                    ? $definition['factory']
                    : Closure::fromCallable($definition['factory']);
                $shared = isset($definition['shared']) && (bool) $definition['shared'];
                $this->bind($abstract, $factory, $shared);
            } else {
                $concrete = $definition['concrete'] ?? $abstract;
                $shared = isset($definition['shared']) && (bool) $definition['shared'];
                if (!is_string($concrete) && !($concrete instanceof Closure)) {
                    throw new RuntimeException("The concrete definition for service {$abstract} must be a string or Closure.");
                }
                $this->bind($abstract, $concrete, $shared);
            }

            if (isset($definition['tags']) && is_array($definition['tags'])) {
                foreach ($definition['tags'] as $tag) {
                    if (!is_string($tag)) {
                        throw new RuntimeException("Invalid tag type for service {$abstract}. Tag must be a string.");
                    }
                    $this->tag($tag, [$abstract]);
                }
            }
            $this->fireEvent(self::EVENT_SERVICE_DEFINITION_BOUND_KEY, $abstract);
        } catch (Throwable $e) {
            $this->fireEvent(self::EVENT_SERVICE_DEFINITION_ERROR_KEY, $abstract, $e);
            throw ServiceDefinitionLoaderException::forDefinitionProcessingFailure($abstract, $e);
        }
    }
}
