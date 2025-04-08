<?php

declare(strict_types=1);

namespace DomainFlow\Application\Attributes;

use Attribute;

/**
 * Service attribute.
 *
 * This attribute is used to define a service in the application container.
 */
#[Attribute(Attribute::TARGET_CLASS)]
class Service
{
    /**
     * Service constructor.
     *
     * @param string $name
     * @param bool $shared
     */
    public function __construct(
        public string $name = '',
        public bool $shared = false
    ) {
    }
}
