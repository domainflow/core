<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversNothing]
class MiddlewareApplicationIntegrationTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_middlewarePipeline(): void
    {
        $app = new Application();
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if default provider is registered
        $providers = $app->getProviders();
        $this->assertCount(1, $providers);
        $this->assertArrayHasKey(EventDispatcherServiceProvider::class, $providers);

        # use middleware helper method and register it
        $myMiddleware = [$this, 'middlewareMethod'];
        $app->useMiddleware($myMiddleware);

        # use inline middleware and register it
        $inlineMiddleware = function (array $payload, callable $next) {
            echo "Middleware 2 processing...\n";
            $payload['value'] += 10;

            return $next($payload);
        };
        $app->useMiddleware($inlineMiddleware);

        # Check if middleware is registered
        $this->assertCount(2, $app->getRegisteredMiddleware());
        $this->assertTrue($app->containsMiddleware($myMiddleware));
        $this->assertTrue($app->containsMiddleware($inlineMiddleware));

        ob_start();
        $result = $app->pipeline(['value' => 5], function (array $payload) {
            echo "Final payload value: " . $payload['value'] . "\n";

            return $payload;
        });
        $output = ob_get_clean();

        $expectedOutput = "Middleware 1 processing...\n"
            . "Middleware 2 processing...\n"
            . "Final payload value: 20\n";

        # Verify the output
        $this->assertEquals($expectedOutput, $output);
        $this->assertEquals(20, $result['value']);
    }

    /**
     * Middleware method to be used in the pipeline.
     *
     * @param array<string, mixed> $payload
     * @param callable $next
     * @return array<string, mixed>
     */
    public function middlewareMethod(
        array $payload,
        callable $next
    ): array {
        echo "Middleware 1 processing...\n";
        $payload['value'] *= 2;

        return $next($payload);
    }
}
