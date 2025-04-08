<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Attribute;

use DomainFlow\Application\Attributes\EventListener;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EventListener::class)]
final class EventListenerTest extends TestCase
{
    public function test_constructor_assigns_event(): void
    {
        $event = 'user.registered';
        $attribute = new EventListener($event);
        $this->assertSame($event, $attribute->event);
    }
}
