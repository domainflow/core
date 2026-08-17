<?php

declare(strict_types=1);

namespace DomainFlow;

use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use DomainFlow\Application\Interface\SystemEventStoreInterface;
use DomainFlow\Application\Traits\AttributeRegistrarTrait;
use DomainFlow\Application\Traits\BootstrappingTrait;
use DomainFlow\Application\Traits\EventManagerTrait;
use DomainFlow\Application\Traits\MiddlewareTrait;
use DomainFlow\Application\Traits\PathEnvironmentTrait;
use DomainFlow\Application\Traits\ServiceDefinitionLoaderTrait;
use DomainFlow\Application\Traits\ServiceProviderTrait;
use DomainFlow\Application\Traits\TerminationTrait;

/**
 * Class Application
 *
 * Application class, extending the dependency injection container and adding
 * bootstrapping, configuration, and lifecycle management features.
 *
 * @use AttributeRegistrarTrait<object>
 */
class Application extends Container
{
    use PathEnvironmentTrait;
    use AttributeRegistrarTrait;
    use BootstrappingTrait;
    use ServiceProviderTrait;
    use TerminationTrait;
    use EventManagerTrait;
    use MiddlewareTrait;
    use ServiceDefinitionLoaderTrait;

    protected const string DEFAULT_FOLDER_NAME_KEY = 'config';

    /**
     * Create a new Application instance.
     *
     * @param string|null $basePath
     * @param EventDispatcherInterface|null $eventDispatcher
     * @param SystemEventStoreInterface|null $systemEventStore
     * @throws EventManagerException|PathEnvironmentException
     */
    public function __construct(
        ?string $basePath = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        ?SystemEventStoreInterface $systemEventStore = null
    ) {
        $this->eventStore = $systemEventStore ?? new SystemEventStore();
        $this->setEventDispatcher($eventDispatcher ?? new BasicEventDispatcher());

        if ($basePath !== null) {
            $this->setBasePath($basePath);
        } else {
            $this->basePath = getcwd() ?: __DIR__;
        }

        // Set a default config path relative to the base path.
        $this->configPath = $this->basePath . DIRECTORY_SEPARATOR . self::DEFAULT_FOLDER_NAME_KEY;
    }
}
