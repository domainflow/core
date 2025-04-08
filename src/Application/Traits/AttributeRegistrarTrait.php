<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Attributes\EventListener;
use DomainFlow\Application\Attributes\Inject;
use DomainFlow\Application\Attributes\Service;
use DomainFlow\Container\Exception\ContainerException;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

/**
 * Trait AttributeRegistrarTrait
 *
 *  Register services, event listeners, and resolve dependencies based on attributes.
 */
trait AttributeRegistrarTrait
{
    /**
     * Auto-register services based on the Service attribute.
     *
     * @param array<class-string> $classNames
     * @throws ContainerException
     * @return void
     */
    public function autoRegisterServices(
        array $classNames
    ): void {
        foreach ($classNames as $className) {
            $reflection = new ReflectionClass($className);
            $attributes = $reflection->getAttributes(Service::class);
            if (!empty($attributes)) {

                /** @var Service $serviceAttr */
                $serviceAttr = $attributes[0]->newInstance();
                $serviceName = $serviceAttr->name ?: $className;
                $shared = $serviceAttr->shared;

                // Bind the service in this container instance.
                $this->bind($serviceName, fn () => new $className(), $shared);
            }
        }
    }

    /**
     * Auto-register event listeners from provided listener instances.
     *
     * @param array<object> $listenerInstances
     * @return void
     */
    public function autoRegisterEventListeners(
        array $listenerInstances
    ): void {
        foreach ($listenerInstances as $listener) {
            $reflection = new ReflectionClass($listener);
            foreach ($reflection->getMethods() as $method) {
                $attributes = $method->getAttributes(EventListener::class);
                foreach ($attributes as $attribute) {

                    /** @var EventListener $listenerAttr */
                    $listenerAttr = $attribute->newInstance();
                    $methodName = $method->getName();

                    /** @var callable(): mixed $callback */
                    $callback = [$listener, $methodName];
                    $this->eventDispatcher->on($listenerAttr->event, $callback);
                }
            }
        }
    }

    /**
     * Auto-resolve dependencies for a given class based on Inject attributes.
     *
     * @param class-string $className
     * @throws ReflectionException|NotFoundExceptionInterface|ContainerExceptionInterface|Throwable
     * @return object
     */
    public function resolveDependencies(
        string $className
    ): object {
        $reflection = new ReflectionClass($className);
        $constructor = $reflection->getConstructor();
        if (!$constructor) {
            return new $className();
        }
        $params = [];
        foreach ($constructor->getParameters() as $param) {
            $attributes = $param->getAttributes(Inject::class);
            $type = $param->getType();

            if (!($type instanceof ReflectionNamedType)) {
                throw new RuntimeException('No valid type specified for parameter ' . $param->getName());
            }
            $typeName = $type->getName();

            if (!empty($attributes)) {
                /** @var Inject $injectAttr */
                $injectAttr = $attributes[0]->newInstance();
                $serviceId = $injectAttr->id ?: $typeName;
                $params[] = $this->get($serviceId);
            } else {
                $params[] = $this->get($typeName);
            }
        }

        return $reflection->newInstanceArgs($params);
    }
}
