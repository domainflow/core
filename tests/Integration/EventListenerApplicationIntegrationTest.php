<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Throwable;

#[CoversNothing]
final class EventListenerApplicationIntegrationTest extends TestCase
{
    /**
     * @throws ContainerExceptionInterface|Throwable
     */
    public function test_userRegisteredEventListeners(): void
    {
        $app = new Application();
        $app->registerProvider(new MultiEventListenerServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(MultiEventListenerServiceProvider::class, $providers);

        $event = 'user.registered';
        $payload = ['username' => 'john_doe'];

        ob_start();
        echo "Executing listeners for event: $event\n";
        foreach ($app->getByTag('user.registered') as $listener) {
            if ($listener instanceof EventListenerInterface) {
                $listener->handle($event, $payload);
            }
        }
        $output = ob_get_clean();

        # Expected output
        $expectedOutput
            = "Executing listeners for event: user.registered\n"
            . "WelcomeEmailListener: Sending welcome email to john_doe\n"
            . "UserRegistrationLogger: User 'john_doe' registered.\n";

        $this->assertEquals($expectedOutput, $output);

        // Check if listeners are registered under the correct tag
        $registeredListeners = $app->getByTag('user.registered');
        $this->assertCount(2, $registeredListeners);

        // Using associative array keys from container
        $this->assertInstanceOf(WelcomeEmailListener::class, $registeredListeners[WelcomeEmailListener::class]);
        $this->assertInstanceOf(UserRegistrationLogger::class, $registeredListeners[UserRegistrationLogger::class]);

        // Check that no listeners remain registered in the container's dispatcher after booting
        $this->assertFalse($app->hasListeners('user.registered'));
        $this->assertFalse($app->hasListeners('user.logged_in'));

        // Since services are non-shared, retrieving them twice should yield different instances
        $listener1 = $app->get(WelcomeEmailListener::class);
        $listener2 = $app->get(WelcomeEmailListener::class);
        $this->assertNotSame($listener1, $listener2);

        // Verify getEvents method
        $events = $app->getEvents();
        $this->assertCount(4, $events);
    }

    /**
     * @throws ContainerExceptionInterface|Throwable
     */
    public function test_multipleEventTypes(): void
    {
        $app = new Application();
        $app->registerProvider(new MultiEventListenerServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(MultiEventListenerServiceProvider::class, $providers);

        ob_start();

        echo "Executing listeners for event: user.registered\n";
        foreach ($app->getByTag('user.registered') as $listener) {
            if ($listener instanceof EventListenerInterface) {
                $listener->handle('user.registered', ['username' => 'alice']);
            }
        }
        echo "Executing listeners for event: user.logged_in\n";
        foreach ($app->getByTag('user.logged_in') as $listener) {
            if ($listener instanceof EventListenerInterface) {
                $listener->handle('user.logged_in', ['username' => 'bob']);
            }
        }

        $output = ob_get_clean();

        // Verify output
        $expectedOutput
            = "Executing listeners for event: user.registered\n"
            . "WelcomeEmailListener: Sending welcome email to alice\n"
            . "UserRegistrationLogger: User 'alice' registered.\n"
            . "Executing listeners for event: user.logged_in\n"
            . "WelcomeEmailListener: Sending welcome email to bob\n"
            . "UserRegistrationLogger: User 'bob' registered.\n";

        $this->assertEquals($expectedOutput, $output);

        // Check if provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(MultiEventListenerServiceProvider::class, $providers);

        // Verify listeners are registered via tag (using associative array keys)
        $registeredListeners = $app->getByTag('user.registered');
        $this->assertCount(2, $registeredListeners);
        $this->assertInstanceOf(WelcomeEmailListener::class, $registeredListeners[WelcomeEmailListener::class]);
        $this->assertInstanceOf(UserRegistrationLogger::class, $registeredListeners[UserRegistrationLogger::class]);

        // Verify that the services are non-shared (i.e. different instances are returned)
        $listener1 = $app->get(WelcomeEmailListener::class);
        $listener2 = $app->get(WelcomeEmailListener::class);
        $this->assertNotSame($listener1, $listener2);

        // Verify that the container does not keep listeners registered for event dispatching after boot
        $this->assertFalse($app->hasListeners('user.registered'));
        $this->assertFalse($app->hasListeners('user.logged_in'));

        // Verify getEvents method
        $events = $app->getEvents();
        $this->assertCount(4, $events);
    }
}

// dummy classes and interface
interface EventListenerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $event,
        array $payload = []
    ): void;
}

class WelcomeEmailListener implements EventListenerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $event,
        array $payload = []
    ): void {
        echo "WelcomeEmailListener: Sending welcome email to " . $payload['username'] . "\n";
    }
}

class UserRegistrationLogger implements EventListenerInterface
{
    /**
     * @param array<string, mixed> $payload
     */
    public function handle(
        string $event,
        array $payload = []
    ): void {
        echo "UserRegistrationLogger: User '" . $payload['username'] . "' registered.\n";
    }
}

class MultiEventListenerServiceProvider extends AbstractServiceProvider
{
    public bool $defer = false;
    protected array $providedServices = [
        WelcomeEmailListener::class,
        UserRegistrationLogger::class,
    ];

    public function register(
        Application $app
    ): void {
        $app->bind(WelcomeEmailListener::class, fn () => new WelcomeEmailListener(), false); # non-shared
        $app->bind(UserRegistrationLogger::class, fn () => new UserRegistrationLogger(), false); # non-shared

        $app->tag('user.registered', [
            WelcomeEmailListener::class,
            UserRegistrationLogger::class,
        ]);
        $app->tag('user.logged_in', [
            WelcomeEmailListener::class,
            UserRegistrationLogger::class,
        ]);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
