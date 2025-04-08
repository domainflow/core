<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

#[CoversClass(Application::class)]
#[CoversClass(BasicEventDispatcher::class)]
final class ApplicationTest extends TestCase
{
    public function test_defaultBasePathAndConfigPath(): void
    {
        $app = new TestableApplication();
        $expectedBasePath = getcwd() ?: __DIR__;

        $this->assertEquals($expectedBasePath, $app->basePath());
        $this->assertEquals($expectedBasePath . DIRECTORY_SEPARATOR . 'config', $app->configPath());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_customBasePath(): void
    {
        $customBase = sys_get_temp_dir();

        $app = new TestableApplication($customBase, new BasicEventDispatcher());
        $expectedBase = rtrim($customBase, DIRECTORY_SEPARATOR);

        $this->assertEquals($expectedBase, $app->basePath());
        $this->assertEquals($expectedBase . DIRECTORY_SEPARATOR . 'config', $app->configPath());
    }

    /**
     * @throws ReflectionException
     */
    public function test_defaultEventDispatcherIsSet(): void
    {
        $app = new TestableApplication();

        $dispatcher = $this->getProtectedProperty($app, 'eventDispatcher');

        $this->assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $this->assertInstanceOf(BasicEventDispatcher::class, $dispatcher);
    }

    /**
     * @throws ReflectionException
     */
    private function getProtectedProperty(object $object, string $property): mixed
    {
        $reflection = new ReflectionClass($object);
        $prop = $reflection->getProperty($property);

        return $prop->getValue($object);
    }
}

// dummy class
class TestableApplication extends Application
{
    public function fireEvent(
        string $event,
        mixed ...$args
    ): void {
        if (isset($this->eventDispatcher)) {
            $this->eventDispatcher->dispatch($event, ...$args);
        }
    }
}
