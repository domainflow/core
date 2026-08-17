<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Enum\EnvironmentEnum;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\MiddlewareException;
use DomainFlow\Application\Exception\PathEnvironmentException;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

#[CoversNothing()]
final class FullLifecycleEventsIntegrationTest extends TestCase
{
    /**
     * @throws EventManagerException|Throwable|MiddlewareException|PathEnvironmentException
     */
    public function test_applicationEmitsCoreEvents(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(
            basePath: __DIR__,
            eventDispatcher: $spyDispatcher
        );

        // Typical steps that trigger various events
        $app->setBasePath(__DIR__);
        $app->setEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);
        $app->registerProvider(new SampleServiceProvider());
        $app->boot();

        // Trigger middleware
        $app->useMiddleware(function ($payload, $next) {
            return $next($payload + 1);
        });
        $this->assertSame(22, $app->pipeline(10, fn ($v) => $v * 2));

        // Application Termination
        $app->terminate();

        // Examine everything the SpyEventDispatcher recorded
        $allEvents = $spyDispatcher->dispatchedEvents;
        $eventNames = array_map(fn ($entry) => $entry[0], $allEvents);

        ob_start();
        foreach ($allEvents as [$evName, $args]) {
            echo "Event fired: $evName (args = " . json_encode($args) . ")\n";
        }
        $output = ob_get_clean();

        $expectedDebugLines = [
            'path.base.set',
            'path.environment.set',
            'service_provider.registered',
            'booting.init',
            'booting.complete',
            'middleware.pipeline.start',
            'middleware.pipeline.end',
            'termination.init',
            'termination.complete',
        ];

        // Check debug output contains all expected events
        foreach ($expectedDebugLines as $eventName) {
            $this->assertStringContainsString(
                "Event fired: {$eventName}",
                $output,
                "Missing {$eventName} line in debug output"
            );
        }

        // Check key assertions
        $this->assertContains('path.base.set', $eventNames, "Expected path.base.set event");
        $this->assertContains('path.environment.set', $eventNames, "Expected path.environment.set event");
        $this->assertContains('service_provider.registered', $eventNames, "Expected service_provider.registered event");
        $this->assertContains('booting.init', $eventNames, "Expected booting.init event");
        $this->assertContains('booting.complete', $eventNames, "Expected booting.complete event");
        $this->assertContains('middleware.pipeline.start', $eventNames, "Expected middleware.pipeline.start event");
        $this->assertContains('middleware.pipeline.end', $eventNames, "Expected middleware.pipeline.end event");
        $this->assertContains('termination.init', $eventNames, "Expected termination.init event");
        $this->assertContains('termination.complete', $eventNames, "Expected termination.complete event");
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_bootErrorEvent(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);

        // Register a booting callback that throws
        $app->booting(function () {
            throw new RuntimeException("Simulated boot error");
        });

        try {
            $app->boot();
            $this->fail("Expected an exception during boot, but none was thrown.");
        } catch (Throwable $e) {
            // Check 'booting.error' was fired
            $eventNames = array_map(fn ($e) => $e[0], $spyDispatcher->dispatchedEvents);
            $this->assertContains('booting.error', $eventNames, "Expected booting.error event");
        }
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_unregisterProviderEvent(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);

        # registerProvider() should trigger 'service_provider.registered'
        $app->registerProvider(new SampleServiceProvider());

        # unregisterProvider() should trigger 'service_provider.unregistered'
        $app->unregisterProvider(SampleServiceProvider::class);

        $eventNames = array_map(fn ($e) => $e[0], $spyDispatcher->dispatchedEvents);
        $this->assertContains('service_provider.registered', $eventNames, "Expected service_provider.registered");
        $this->assertContains('service_provider.unregistered', $eventNames, "Expected service_provider.unregistered");
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_deferredProviderEvent(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);

        $app->registerProvider(new FullLifecycleLazyServiceProvider());
        $app->boot();

        // This call should now trigger deferred loading.
        $service = $app->get(FullLifecycleLazyService::class);
        $this->assertInstanceOf(FullLifecycleLazyService::class, $service);

        // Examine the dispatched events.
        $eventNames = array_map(fn ($entry) => $entry[0], $spyDispatcher->dispatchedEvents);

        $this->assertContains(
            'service_provider.deferred.loaded',
            $eventNames,
            "Expected service_provider.deferred.loaded event"
        );
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_configPathEvents(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);

        $app->setConfigPath(__DIR__);  // path.config.set

        try {
            $app->setConfigPath(__DIR__ . '/nonexistent_dir/invalid');
        } catch (Throwable) {
            // Should trigger path.config.error
        }

        $eventNames = array_map(fn ($e) => $e[0], $spyDispatcher->dispatchedEvents);
        $this->assertContains('path.config.set', $eventNames, "Expected path.config.set for valid directory");
        $this->assertContains('path.config.error', $eventNames, "Expected path.config.error for invalid directory");
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_middlewareErrorEvent(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);
        $app->boot();

        // Add a middleware that throws
        $app->useMiddleware(function ($payload, $next) {
            throw new RuntimeException("Simulated middleware error");
        });

        try {
            $app->pipeline(123, fn ($x) => $x);
            $this->fail("Expected middleware to throw");
        } catch (Throwable $e) {
            // Confirm 'middleware.error'
            $eventNames = array_map(fn ($e) => $e[0], $spyDispatcher->dispatchedEvents);
            $this->assertContains('middleware.error', $eventNames, "Expected middleware.error event");
        }
    }

    /**
     * @throws EventManagerException|Throwable|PathEnvironmentException
     */
    public function test_terminationErrorEvent(): void
    {
        $spyDispatcher = new SpyEventDispatcher();
        $app = new Application(eventDispatcher: $spyDispatcher);
        $app->boot();

        // Register a failing termination callback
        $app->registerTerminationCallback(function () {
            throw new RuntimeException("Simulated termination failure");
        });

        try {
            $app->terminate();
            $this->fail("Expected a termination error to be thrown");
        } catch (Throwable $e) {
            // Confirm 'termination.error'
            $eventNames = array_map(fn ($e) => $e[0], $spyDispatcher->dispatchedEvents);
            $this->assertContains('termination.error', $eventNames, "Expected termination.error event");
        }
    }
}

# Dummy classes
class SpyEventDispatcher extends BasicEventDispatcher
{
    /** @var list<array{string, array}> */
    public array $dispatchedEvents = [];

    public function dispatch(
        string $event,
        mixed ...$args
    ): void {
        $this->dispatchedEvents[] = [$event, $args];
        parent::dispatch($event, ...$args);
    }
}

class SampleServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = ['sample_service'];
    public bool $defer = false;

    public function register(
        Application $app
    ): void {
        $app->bind('sample_service', fn () => 'SampleServiceInstance', true);
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

class DeferredFooProvider extends AbstractServiceProvider
{
    public bool $defer = true;
    protected array $providedServices = [FooService::class];

    public function register(
        Application $app
    ): void {
        $app->bind(FooService::class, fn () => new FooService(), true);
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

class FooService
{
    public function sayHello(): string
    {
        return "Hello from FooService!";
    }
}

class FullLifecycleLazyServiceProvider extends AbstractServiceProvider
{
    public bool $defer = true;
    protected array $providedServices = [FullLifecycleLazyService::class];

    public function register(
        Application $app
    ): void {
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

class FullLifecycleLazyService
{
    public function process(): string
    {
        return "LazyService has been loaded and processed!";
    }
}
