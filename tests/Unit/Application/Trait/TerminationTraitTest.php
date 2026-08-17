<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\TerminationException;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(TerminationException::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
final class TerminationTraitTest extends TestCase
{
    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithoutCallbacks(): void
    {
        $app = new Application();
        $app->terminate();

        $events = $this->terminationEvents($app);
        $this->assertNotEmpty($events, 'No termination events were fired.');
        $this->assertEquals('termination.init', $events[0]['event']);
        $this->assertEquals('termination.complete', end($events)['event']);
    }

    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithSuccessfulCallbacks(): void
    {
        $app = new Application();
        $app->registerTerminationCallback(function () use (&$called) {
            $called = true;
        });
        $app->terminate();

        $this->assertTrue($called ?? false, 'Termination callback was not called.');
        $events = $this->terminationEvents($app);
        $this->assertEquals('termination.init', $events[0]['event']);
        $this->assertEquals('termination.complete', end($events)['event']);
    }

    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithCallbackException(): void
    {
        $app = new Application();
        $exceptionMessage = 'Callback failure';
        $app->registerTerminationCallback(function () use ($exceptionMessage) {
            throw new Exception($exceptionMessage);
        });

        try {
            $app->terminate();
            $this->fail('Expected TerminationException was not thrown.');
        } catch (TerminationException $e) {
            $events = $this->terminationEvents($app);
            $this->assertEquals('termination.init', $events[0]['event']);

            $errorFound = false;
            foreach ($events as $event) {
                if ($event['event'] === 'termination.error') {
                    $errorFound = true;
                    $this->assertInstanceOf(Exception::class, $event['args'][0]);
                    $this->assertEquals($exceptionMessage, $event['args'][0]->getMessage());
                    break;
                }
            }
            $this->assertTrue($errorFound, "Termination error event not fired.");
        }
    }

    /**
     * Flatten Application::getEvents() into chronological order and keep
     * only the termination.* lifecycle events, ignoring the unrelated
     * event_manager.dispatcher.set event fired during construction.
     *
     * @return list<array{event: string, args: array<int, mixed>}>
     */
    private function terminationEvents(Application $app): array
    {
        $flat = [];
        foreach ($app->getEvents() as $event => $entries) {
            if (!str_starts_with($event, 'termination.')) {
                continue;
            }
            foreach ($entries as $entry) {
                $flat[] = ['event' => $event, 'order' => $entry['order'], 'args' => $entry['args']];
            }
        }
        usort($flat, static fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return $flat;
    }
}
