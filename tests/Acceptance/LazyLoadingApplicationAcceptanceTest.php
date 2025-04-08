<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Acceptance;

namespace DomainFlow\Tests\Acceptance;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

#[CoversNothing]
class LazyLoadingApplicationAcceptanceTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_userReceivesCorrectServiceOutputWhenRequestingDynamicService(): void
    {
        // Here, we simulate a high-level "start" command.
        $app = new Application();
        $app->registerProvider(new LazyServiceProvider());
        $app->boot();

        // Instead of calling $app->get() directly
        $output = $this->simulateUserActionRequest($app);

        # Verify the output
        $expectedOutput = "System is running, but LazyService is not yet used.\n"
            . "LazyService has been loaded and processed!\n";

        $this->assertEquals($expectedOutput, $output);
    }

    /**
     * @throws ContainerExceptionInterface| NotFoundExceptionInterface|Throwable
     */
    private function simulateUserActionRequest(
        Application $app
    ): string {
        ob_start();
        echo "System is running, but LazyService is not yet used.\n";
        $service = $app->get(LazyService::class);
        echo $service->process() . "\n";

        return ob_get_clean();
    }
}

# dummy classes
class LazyService
{
    public function process(): string
    {
        return "LazyService has been loaded and processed!";
    }
}

class LazyServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [LazyService::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        $app->bind(LazyService::class, function () {
            return new LazyService();
        }, true);

        // Immediately resolve and store in cache when NOT deferred
        if (!$this->defer) {
            $app->get(LazyService::class);
        }
    }

    public function provides(): array
    {
        return $this->providedServices;
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
