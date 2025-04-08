<?php

declare(strict_types=1);

namespace DomainFlow\Application\Traits;

use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\MiddlewareException;
use Throwable;

trait MiddlewareTrait
{
    protected const string EVENT_MIDDLEWARE_PIPELINE_START_KEY = 'middleware.pipeline.start';
    protected const string EVENT_MIDDLEWARE_PIPELINE_END_KEY = 'middleware.pipeline.end';
    protected const string EVENT_MIDDLEWARE_ERROR_KEY = 'middleware.error';

    /**
     * Middleware stack.
     *
     * @var list<callable>
     */
    protected array $middleware = [];

    /**
     * Get all registered middleware.
     *
     * @return array<callable>
     */
    public function getRegisteredMiddleware(): array
    {
        return $this->middleware;
    }

    /**
     * Check if a middleware is registered.
     *
     * @param callable $middleware
     * @return bool
     */
    public function containsMiddleware(
        callable $middleware
    ): bool {
        return in_array($middleware, $this->middleware, true);
    }

    /**
     * Register a middleware callable.
     *
     * Middleware must accept a payload and a next callable.
     *
     * @param callable $middleware
     * @return void
     */
    public function useMiddleware(
        callable $middleware
    ): void {
        $this->middleware[] = $middleware;
    }

    /**
     * Execute a middleware pipeline with a given payload.
     *
     * Fires an event at the start and end of the pipeline, and if an error occurs,
     * fires an error event and throws a MiddlewareException.
     *
     * @param mixed $payload
     * @param callable $final
     * @throws MiddlewareException|EventManagerException
     * @return mixed
     */
    public function pipeline(
        mixed $payload,
        callable $final
    ): mixed {
        $this->fireEvent(self::EVENT_MIDDLEWARE_PIPELINE_START_KEY, $payload);

        try {
            $pipeline = array_reduce(
                array_reverse($this->middleware),
                static function (callable $next, callable $middleware): callable {
                    return function ($payload) use ($middleware, $next) {
                        return $middleware($payload, $next);
                    };
                },
                $final
            );

            $result = $pipeline($payload);
            $this->fireEvent(self::EVENT_MIDDLEWARE_PIPELINE_END_KEY, $result);

            return $result;
        } catch (Throwable $e) {
            $this->fireEvent(self::EVENT_MIDDLEWARE_ERROR_KEY, $e);
            throw MiddlewareException::forPipelineFailure($e->getMessage(), $e);
        }
    }
}
