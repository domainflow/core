<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

#[CoversNothing]
class ConsoleServiceApplicationIntegrationTest extends TestCase
{
    /**
     * @throws Throwable|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function test_consoleServiceRegistrationAndBoot(): void
    {
        $app = new Application();
        $app->registerProvider(new ConsoleServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(ConsoleServiceProvider::class, $providers);

        $service = $app->get(ConsoleService::class);

        # Check the method output
        $this->assertEquals('Hello from ConsoleService!', $service->sayHello());
    }

}

class ConsoleService
{
    public function sayHello(): string
    {
        return 'Hello from ConsoleService!';
    }
}

class ConsoleServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [ConsoleService::class];
    public bool $defer = false;

    public function register(
        Application $app
    ): void {
        $app->bind(ConsoleService::class, fn () => new ConsoleService(), true);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
