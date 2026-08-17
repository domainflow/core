<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\ServiceProvider\EventDispatcherServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversNothing]
class SimpleEventApplicationIntegrationTest extends TestCase
{
    /**
     * @throws EventManagerException|Throwable
     */
    public function test_userRegisteredEventFiring(): void
    {
        $app = new Application();
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if default provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(EventDispatcherServiceProvider::class, $providers);
        $this->assertCount(1, $providers);

        ob_start();

        $app->on('user.registered', function ($username) {
            echo "User '$username' has registered.\n";
        });
        $app->fireEvent('user.registered', 'john_doe');
        $output = ob_get_clean();

        # expected output
        $this->assertEquals("User 'john_doe' has registered.\n", $output);

        # listeners are registered
        $this->assertTrue($app->hasListeners('user.registered'));

        # listeners are not registered in the container
        $registeredListeners = $app->getByTag('user.registered');
        $this->assertCount(0, $registeredListeners);
    }

    /**
     * @throws EventManagerException|Throwable
     */
    public function test_multipleListenersAndOnce(): void
    {
        $app = new Application();
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if default provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(EventDispatcherServiceProvider::class, $providers);
        $this->assertCount(1, $providers);

        ob_start();

        $app->on('order.created', function ($orderId) {
            echo "Listener1: Order $orderId created.\n";
        });
        $app->once('order.created', function ($orderId) {
            echo "Listener2: Order $orderId created (once).\n";
        });
        $app->on('order.created', function ($orderId) {
            echo "Listener3: Order $orderId created.\n";
        });

        $app->fireEvent('order.created', 221133);
        $app->fireEvent('order.created', 112233);

        $output = ob_get_clean();

        $expectedOutput
            = "Listener1: Order 221133 created.\n"
            . "Listener2: Order 221133 created (once).\n"
            . "Listener3: Order 221133 created.\n"
            . "Listener1: Order 112233 created.\n"
            . "Listener3: Order 112233 created.\n";

        # expected output
        $this->assertEquals($expectedOutput, $output);

        # listeners are registered
        $this->assertTrue($app->hasListeners('order.created'));
        $this->assertTrue($app->hasListeners('order.created'));

        # listeners are not registered in the container
        $registeredListeners = $app->getByTag('order.created');
        $this->assertCount(0, $registeredListeners);
    }
}
