<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Attribute;

use DomainFlow\Application\Attributes\Service;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Service::class)]
final class ServiceTest extends TestCase
{
    public function test_default_values(): void
    {
        $attribute = new Service();
        $this->assertSame('', $attribute->name);
        $this->assertFalse($attribute->shared);
    }

    public function test_constructor_assigns_values(): void
    {
        $name = 'MyService';
        $attribute = new Service($name, true);
        $this->assertSame($name, $attribute->name);
        $this->assertTrue($attribute->shared);
    }
}
