<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Interface\EventDispatcherInterface;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * Confirms Application's lifecycle event stream (on()/fireEvent()/once()) and
 * DI consumers resolving EventDispatcherInterface observe the exact same
 * dispatcher instance rather than two independent event streams.
 */
#[CoversNothing]
class EventDispatcherIdentityIntegrationTest extends TestCase
{
    /**
     * @throws Throwable
     */
    public function test_defaultDispatcherIsResolvedFromContainerAsTheSameInstance(): void
    {
        $app = new Application();
        $app->boot();

        $resolved = $app->get(EventDispatcherInterface::class);

        $this->assertSame($app->getEventDispatcher(), $resolved);
    }

    /**
     * @throws Throwable
     */
    public function test_constructorInjectedDispatcherIsResolvedFromContainerAsTheSameInstance(): void
    {
        $injectedDispatcher = new BasicEventDispatcher();
        $app = new Application(eventDispatcher: $injectedDispatcher);
        $app->boot();

        $resolved = $app->get(EventDispatcherInterface::class);

        $this->assertSame($injectedDispatcher, $resolved);
    }

    /**
     * @throws Throwable
     */
    public function test_diConsumerObservesApplicationRegisteredListeners(): void
    {
        $app = new Application();
        $app->boot();

        ob_start();
        $app->on('order.shipped', function (string $orderId): void {
            echo "Order $orderId shipped.\n";
        });

        $consumer = $app->get(NotificationDispatcherConsumer::class);
        $consumer->notifyOrderShipped('4711');
        $output = ob_get_clean();

        $this->assertEquals("Order 4711 shipped.\n", $output);
    }
}

# dummy class
class NotificationDispatcherConsumer
{
    public function __construct(
        private readonly EventDispatcherInterface $dispatcher
    ) {
    }

    public function notifyOrderShipped(string $orderId): void
    {
        $this->dispatcher->dispatch('order.shipped', $orderId);
    }
}
