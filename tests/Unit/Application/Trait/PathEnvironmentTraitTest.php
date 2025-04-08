<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Enum\EnvironmentEnum;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Application\Traits\PathEnvironmentTrait;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionException;

#[CoversClass(Application::class)]
#[CoversClass(PathEnvironmentException::class)]
final class PathEnvironmentTraitTest extends TestCase
{
    private DummyPathEnvironment $dummy;

    protected function setUp(): void
    {
        $this->dummy = new DummyPathEnvironment();
    }

    /**
     * @throws EventManagerException|PathEnvironmentException|ReflectionException
     */
    public function test_setBasePath_valid(): void
    {
        $validDir = __DIR__;
        $this->dummy->events = [];
        $result = $this->dummy->setBasePath($validDir);
        $basePath = $this->getProtectedProperty($this->dummy, 'basePath');

        $this->assertSame($validDir, $basePath);
        $this->assertSame($this->dummy, $result);
        $this->assertEventFired($this->dummy->events, 'path.base.set', $validDir);
    }

    /**
     * @throws EventManagerException
     */
    public function test_setBasePath_invalid(): void
    {
        $this->dummy->events = [];
        $invalidDir = '/nonexistent_directory_xyz';

        $this->expectException(PathEnvironmentException::class);
        $this->expectExceptionMessage("Invalid base path provided:");

        $this->dummy->setBasePath($invalidDir);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_basePath_method(): void
    {
        $validDir = __DIR__;
        $this->dummy->setBasePath($validDir);
        $subPath = 'sub/dir';
        $expected = $validDir . DIRECTORY_SEPARATOR . $subPath;

        $this->assertSame($expected, $this->dummy->basePath($subPath));
        $this->assertSame($validDir, $this->dummy->basePath());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException|ReflectionException
     */
    public function test_setConfigPath_valid(): void
    {
        $validDir = __DIR__;
        $this->dummy->events = [];
        $result = $this->dummy->setConfigPath($validDir);
        $configPath = $this->getProtectedProperty($this->dummy, 'configPath');

        $this->assertSame($validDir, $configPath);
        $this->assertSame($this->dummy, $result);
        $this->assertEventFired($this->dummy->events, 'path.config.set', $validDir);
    }

    /**
     * @throws EventManagerException
     */
    public function test_setConfigPath_invalid(): void
    {
        $this->dummy->events = [];
        $invalidDir = '/nonexistent_directory_abc';

        $this->expectException(PathEnvironmentException::class);
        $this->expectExceptionMessage("Invalid configuration path provided:");

        $this->dummy->setConfigPath($invalidDir);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_configPath_method(): void
    {
        $validDir = __DIR__;
        $this->dummy->setConfigPath($validDir);
        $subPath = 'config/sub';
        $expected = $validDir . DIRECTORY_SEPARATOR . $subPath;

        $this->assertSame($expected, $this->dummy->configPath($subPath));
        $this->assertSame($validDir, $this->dummy->configPath());
    }

    public function test_default_environment(): void
    {
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_PRODUCTION, $this->dummy->environment());
    }

    /**
     * @throws EventManagerException
     */
    public function test_setEnvironment_and_isEnvironment(): void
    {
        $this->dummy->events = [];
        $this->dummy->setEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);

        $this->assertSame(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT, $this->dummy->environment());
        $this->assertTrue($this->dummy->isEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT));
        $this->assertEventFired($this->dummy->events, 'path.environment.set', EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);
    }

    /**
     * @param array<string, mixed> $events
     * @param string $expectedEvent
     * @param null $expectedArg
     * @return void
     */
    private function assertEventFired(
        array $events,
        string $expectedEvent,
        $expectedArg = null
    ): void {
        foreach ($events as [$event, $args]) {
            if ($event === $expectedEvent) {
                if ($expectedArg !== null) {
                    $this->assertSame($expectedArg, $args[0]);
                }

                return;
            }
        }
        $this->fail("Event {$expectedEvent} was not fired.");
    }

    /**
     * @throws ReflectionException
     */
    private function getProtectedProperty(
        object $object,
        string $property
    ) {
        $refClass = new ReflectionClass($object);
        $refProp = $refClass->getProperty($property);

        return $refProp->getValue($object);
    }
}

# Dummy class
class DummyPathEnvironment
{
    use PathEnvironmentTrait;

    public array $events = [];

    public function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->events[] = [$event, $args];
    }
}
