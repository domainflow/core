<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Enum\EnvironmentEnum;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(PathEnvironmentException::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
final class PathEnvironmentTraitTest extends TestCase
{
    private Application $app;

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    protected function setUp(): void
    {
        $this->app = new Application();
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_setBasePath_valid(): void
    {
        $validDir = __DIR__;
        $result = $this->app->setBasePath($validDir);

        $this->assertSame($validDir, $this->app->basePath());
        $this->assertSame($this->app, $result);
        $this->assertEventFired($this->app, 'path.base.set', $validDir);
    }

    /**
     * @throws EventManagerException
     */
    public function test_setBasePath_invalid(): void
    {
        $invalidDir = '/nonexistent_directory_xyz';

        $this->expectException(PathEnvironmentException::class);
        $this->expectExceptionMessage("Invalid base path provided:");

        $this->app->setBasePath($invalidDir);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_basePath_method(): void
    {
        $validDir = __DIR__;
        $this->app->setBasePath($validDir);
        $subPath = 'sub/dir';
        $expected = $validDir . DIRECTORY_SEPARATOR . $subPath;

        $this->assertSame($expected, $this->app->basePath($subPath));
        $this->assertSame($validDir, $this->app->basePath());
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_setConfigPath_valid(): void
    {
        $validDir = __DIR__;
        $result = $this->app->setConfigPath($validDir);

        $this->assertSame($validDir, $this->app->configPath());
        $this->assertSame($this->app, $result);
        $this->assertEventFired($this->app, 'path.config.set', $validDir);
    }

    /**
     * @throws EventManagerException
     */
    public function test_setConfigPath_invalid(): void
    {
        $invalidDir = '/nonexistent_directory_abc';

        $this->expectException(PathEnvironmentException::class);
        $this->expectExceptionMessage("Invalid configuration path provided:");

        $this->app->setConfigPath($invalidDir);
    }

    /**
     * @throws EventManagerException|PathEnvironmentException
     */
    public function test_configPath_method(): void
    {
        $validDir = __DIR__;
        $this->app->setConfigPath($validDir);
        $subPath = 'config/sub';
        $expected = $validDir . DIRECTORY_SEPARATOR . $subPath;

        $this->assertSame($expected, $this->app->configPath($subPath));
        $this->assertSame($validDir, $this->app->configPath());
    }

    public function test_default_environment(): void
    {
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_PRODUCTION, $this->app->environment());
    }

    /**
     * @throws EventManagerException
     */
    public function test_setEnvironment_and_isEnvironment(): void
    {
        $this->app->setEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);

        $this->assertSame(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT, $this->app->environment());
        $this->assertTrue($this->app->isEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT));
        $this->assertEventFired($this->app, 'path.environment.set', EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);
    }

    private function assertEventFired(
        Application $app,
        string $expectedEvent,
        mixed $expectedArg = null
    ): void {
        $events = $app->getEvents();
        $this->assertArrayHasKey($expectedEvent, $events, "Event {$expectedEvent} was not fired.");

        if ($expectedArg !== null) {
            $this->assertSame($expectedArg, $events[$expectedEvent][0]['args'][0]);
        }
    }
}
