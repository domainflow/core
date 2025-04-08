<?php

declare(strict_types=1);

namespace DomainFlow\Application\Attributes;

use Attribute;

/**
 * EventListener attribute.
 *
 * This attribute is used to define an event listener.
 */
#[Attribute(Attribute::TARGET_METHOD)]
class EventListener
{
    public function __construct(public string $event)
    {
    }
}
