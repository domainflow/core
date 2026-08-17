<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\MiddlewareException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(MiddlewareException::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
final class MiddlewareTraitTest extends TestCase
{
    public function test_getRegisteredMiddleware(): void
    {
        $app = new Application();
        $middleware1 = function ($payload, callable $next) {
            return $next($payload + 2);
        };
        $middleware2 = function ($payload, callable $next) {
            return $next($payload * 2);
        };

        $app->useMiddleware($middleware1);
        $app->useMiddleware($middleware2);

        $registered = $app->getRegisteredMiddleware();
        $this->assertCount(2, $registered, 'There should be exactly 2 middleware registered');
        $this->assertSame($middleware1, $registered[0], 'The first middleware should match the one added');
        $this->assertSame($middleware2, $registered[1], 'The second middleware should match the one added');
    }

    public function test_containsMiddleware(): void
    {
        $app = new Application();
        $middleware1 = function ($payload, callable $next) {
            return $next($payload + 5);
        };
        $middleware2 = function ($payload, callable $next) {
            return $next($payload * 5);
        };

        $app->useMiddleware($middleware1);

        $this->assertTrue($app->containsMiddleware($middleware1), 'Handler should contain middleware1');

        $this->assertFalse($app->containsMiddleware($middleware2), 'Handler should not contain middleware2');
    }

    /**
     * @throws EventManagerException|MiddlewareException
     */
    public function test_pipeline_normal(): void
    {
        $app = new Application();
        $app->useMiddleware(function ($payload, callable $next) {
            return $next($payload + 1);
        });
        $app->useMiddleware(function ($payload, callable $next) {
            return $next($payload * 3);
        });

        $final = function ($payload) {
            return $payload;
        };

        $result = $app->pipeline(2, $final);

        $this->assertSame(9, $result);

        $this->assertArrayHasKey('middleware.pipeline.start', $app->getEvents());
        $this->assertArrayHasKey('middleware.pipeline.end', $app->getEvents());
    }

    /**
     * @throws MiddlewareException|EventManagerException
     */
    public function test_pipeline_error(): void
    {
        $app = new Application();

        $app->useMiddleware(function ($payload, callable $next) {
            throw new Exception("Middleware failed");
        });

        $final = function ($payload) {
            return $payload;
        };

        $this->expectException(MiddlewareException::class);
        try {
            $app->pipeline(10, $final);
        } catch (MiddlewareException $e) {
            $this->assertArrayHasKey('middleware.error', $app->getEvents());
            $this->assertStringContainsString("Middleware failed", $e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws EventManagerException|MiddlewareException
     */
    public function test_useMiddleware_and_pipeline_without_any_middleware(): void
    {
        $app = new Application();

        $final = function ($payload) {
            return $payload * 2;
        };

        $result = $app->pipeline(5, $final);

        $this->assertSame(10, $result);

        $this->assertArrayHasKey('middleware.pipeline.start', $app->getEvents());
        $this->assertArrayHasKey('middleware.pipeline.end', $app->getEvents());
    }
}
