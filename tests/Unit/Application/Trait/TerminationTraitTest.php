<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use DomainFlow\Application;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\TerminationException;
use DomainFlow\Application\Traits\TerminationTrait;
use Exception;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Application::class)]
#[CoversClass(TerminationException::class)]
final class TerminationTraitTest extends TestCase
{
    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithoutCallbacks(): void
    {
        $dummy = new DummyTerminationTester();
        $dummy->terminate();

        $events = $dummy->getEvents();
        $this->assertNotEmpty($events, 'No events were fired.');
        $this->assertEquals('termination.init', $events[0]['event']);
        $this->assertEquals('termination.complete', end($events)['event']);
    }

    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithSuccessfulCallbacks(): void
    {
        $dummy = new DummyTerminationTester();
        $dummy->registerTerminationCallback(function () use (&$called) {
            $called = true;
        });
        $dummy->terminate();

        $this->assertTrue($called ?? false, 'Termination callback was not called.');
        $events = $dummy->getEvents();
        $this->assertEquals('termination.init', $events[0]['event']);
        $this->assertEquals('termination.complete', end($events)['event']);
    }

    /**
     * @throws TerminationException|EventManagerException
     */
    public function test_terminateWithCallbackException(): void
    {
        $dummy = new DummyTerminationTester();
        $exceptionMessage = 'Callback failure';
        $dummy->registerTerminationCallback(function () use ($exceptionMessage) {
            throw new Exception($exceptionMessage);
        });

        try {
            $dummy->terminate();
            $this->fail('Expected TerminationException was not thrown.');
        } catch (TerminationException $e) {
            $events = $dummy->getEvents();
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

}

# Dummy class
class DummyTerminationTester
{
    use TerminationTrait;

    public array $events = [];

    protected function fireEvent(
        string $event,
        mixed ...$args
    ): void {
        $this->events[] = ['event' => $event, 'args' => $args];
    }

    public function getEvents(): array
    {
        return $this->events;
    }
}
