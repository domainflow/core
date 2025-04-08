<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\MiddlewareException;
use DomainFlow\Application\Traits\MiddlewareTrait;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(MiddlewareException::class)]
final class MiddlewareTraitTest extends TestCase
{
    public function test_getRegisteredMiddleware(): void
    {
        $handler = new DummyMiddlewareHandler();
        $middleware1 = function ($payload, callable $next) {
            return $next($payload + 2);
        };
        $middleware2 = function ($payload, callable $next) {
            return $next($payload * 2);
        };

        $handler->useMiddleware($middleware1);
        $handler->useMiddleware($middleware2);

        $registered = $handler->getRegisteredMiddleware();
        $this->assertCount(2, $registered, 'There should be exactly 2 middleware registered');
        $this->assertSame($middleware1, $registered[0], 'The first middleware should match the one added');
        $this->assertSame($middleware2, $registered[1], 'The second middleware should match the one added');
    }

    public function test_containsMiddleware(): void
    {
        $handler = new DummyMiddlewareHandler();
        $middleware1 = function ($payload, callable $next) {
            return $next($payload + 5);
        };
        $middleware2 = function ($payload, callable $next) {
            return $next($payload * 5);
        };

        $handler->useMiddleware($middleware1);

        $this->assertTrue($handler->containsMiddleware($middleware1), 'Handler should contain middleware1');

        $this->assertFalse($handler->containsMiddleware($middleware2), 'Handler should not contain middleware2');
    }

    /**
     * @throws EventManagerException| MiddlewareException
     */
    public function test_pipeline_normal(): void
    {
        $handler = new DummyMiddlewareHandler();
        $handler->useMiddleware(function ($payload, callable $next) {
            return $next($payload + 1);
        });
        $handler->useMiddleware(function ($payload, callable $next) {
            return $next($payload * 3);
        });

        $final = function ($payload) {
            return $payload;
        };

        $result = $handler->pipeline(2, $final);

        $this->assertSame(9, $result);

        $this->assertEventFired($handler->firedEvents, 'middleware.pipeline.start');
        $this->assertEventFired($handler->firedEvents, 'middleware.pipeline.end');
    }

    /**
     * @throws MiddlewareException|EventManagerException
     */
    public function test_pipeline_error(): void
    {
        $handler = new DummyMiddlewareHandler();

        $handler->useMiddleware(function ($payload, callable $next) {
            throw new Exception("Middleware failed");
        });

        $final = function ($payload) {
            return $payload;
        };

        $this->expectException(MiddlewareException::class);
        try {
            $handler->pipeline(10, $final);
        } catch (MiddlewareException $e) {
            $this->assertEventFired($handler->firedEvents, 'middleware.error');
            $this->assertStringContainsString("Middleware failed", $e->getMessage());
            throw $e;
        }
    }

    /**
     * @throws EventManagerException|MiddlewareException
     */
    public function test_useMiddleware_and_pipeline_without_any_middleware(): void
    {
        $handler = new DummyMiddlewareHandler();

        $final = function ($payload) {
            return $payload * 2;
        };

        $result = $handler->pipeline(5, $final);

        $this->assertSame(10, $result);

        $this->assertEventFired($handler->firedEvents, 'middleware.pipeline.start');
        $this->assertEventFired($handler->firedEvents, 'middleware.pipeline.end');
    }

    /**
     * @param array<string, mixed> $events
     */
    private function assertEventFired(
        array $events,
        string $expectedEvent
    ): void {
        foreach ($events as [$event, $args]) {
            if ($event === $expectedEvent) {
                return;
            }
        }
        $this->fail("Event {$expectedEvent} was not fired.");
    }
}

# Dummy class
class DummyMiddlewareHandler
{
    use MiddlewareTrait;

    /**
     * @var array<string, mixed> $firedEvents
     */
    public array $firedEvents = [];

    /**
     * @param mixed ...$args
     */
    public function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->firedEvents[] = [$event, $args];
    }
}
