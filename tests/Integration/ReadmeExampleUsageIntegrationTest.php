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

/**
 * Runs the exact "Example Usage" snippet from README.md so it stays
 * verified: MyServiceProvider extends AbstractServiceProvider implementing
 * only register(), relying on the base class's default boot(), provides(),
 * and isDeferred() implementations.
 */
#[CoversNothing]
class ReadmeExampleUsageIntegrationTest extends TestCase
{
    /**
     * @throws Throwable|NotFoundExceptionInterface|ContainerExceptionInterface
     */
    public function test_readmeExampleUsageSnippetRuns(): void
    {
        // 3. Create a new application.
        $app = new Application();

        // 4. Register your provider.
        $app->registerProvider(new ReadmeMyServiceProvider());

        // 5. Boot the application (register event listeners, run boot callbacks, etc.).
        $app->boot();

        // 6. Get your service.
        $service = $app->get(ReadmeMyService::class);
        $this->assertSame('done', $service->doSomething());
    }
}

// 1. Define the service your provider will register.
class ReadmeMyService
{
    public function doSomething(): string
    {
        return 'done';
    }
}

// 2. Define your own service provider. AbstractServiceProvider only requires
//    register(); boot(), provides(), and isDeferred() already have usable
//    defaults (isDeferred() reflects $defer below).
class ReadmeMyServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [ReadmeMyService::class];
    public bool $defer = true; // Lazy loading, only load on first use

    public function register(Application $app): void
    {
        // Bind a service...
        $app->bind(ReadmeMyService::class, fn () => new ReadmeMyService(), true);
    }
}
