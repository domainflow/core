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

    /**
     * @throws Throwable
     */
    public function test_containerResolvedDispatcherReflectsPostBootSetEventDispatcherSwap(): void
    {
        $app = new Application();
        $app->boot();

        $swapped = new BasicEventDispatcher();
        $app->setEventDispatcher($swapped);

        $resolved = $app->get(EventDispatcherInterface::class);

        $this->assertSame(
            $swapped,
            $resolved,
            'A binding resolved after a post-boot setEventDispatcher() call must reflect the new dispatcher, not the boot-time snapshot.'
        );
    }

    /**
     * @throws Throwable
     */
    public function test_diConsumerObservesApplicationRegisteredListenersBeforeAndAfterPostBootDispatcherSwap(): void
    {
        $app = new Application();
        $app->boot();

        ob_start();
        $app->on('order.shipped', function (string $orderId): void {
            echo "Before swap: order $orderId shipped.\n";
        });
        $consumerBeforeSwap = $app->get(NotificationDispatcherConsumer::class);
        $consumerBeforeSwap->notifyOrderShipped('1');
        $outputBeforeSwap = ob_get_clean();

        $this->assertEquals("Before swap: order 1 shipped.\n", $outputBeforeSwap);

        $app->setEventDispatcher(new BasicEventDispatcher());

        ob_start();
        $app->on('order.shipped', function (string $orderId): void {
            echo "After swap: order $orderId shipped.\n";
        });
        $consumerAfterSwap = $app->get(NotificationDispatcherConsumer::class);
        $consumerAfterSwap->notifyOrderShipped('2');
        $outputAfterSwap = ob_get_clean();

        $this->assertEquals(
            "After swap: order 2 shipped.\n",
            $outputAfterSwap,
            'A consumer resolved after a post-boot setEventDispatcher() swap must observe listeners registered on the new dispatcher.'
        );
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
