<?php

declare(strict_types=1);

namespace DomainFlow\Application\Attributes;

use Attribute;

/**
 * Inject attribute.
 *
 * This attribute is used to inject a service into a class constructor.
 */
#[Attribute(Attribute::TARGET_PARAMETER)]
class Inject
{
    public function __construct(public ?string $id = null)
    {
    }
}
