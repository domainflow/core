<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Attribute;

use DomainFlow\Application\Attributes\Inject;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Inject::class)]
final class InjectTest extends TestCase
{
    public function test_default_id_is_null(): void
    {
        $attribute = new Inject();
        $this->assertNull($attribute->id);
    }

    public function test_constructor_assigns_id(): void
    {
        $id = 'my_service';
        $attribute = new Inject($id);
        $this->assertSame($id, $attribute->id);
    }
}
