<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Enum;

use DomainFlow\Application\Enum\EnvironmentEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(EnvironmentEnum::class)]
final class EnvironmentEnumTest extends TestCase
{
    public function test_fromString_knownValues(): void
    {
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_PRODUCTION, EnvironmentEnum::fromString('production'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT, EnvironmentEnum::fromString('development'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_STAGING, EnvironmentEnum::fromString('staging'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_TESTING, EnvironmentEnum::fromString('testing'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_CUSTOM, EnvironmentEnum::fromString('custom'));

        $this->assertSame(EnvironmentEnum::ENVIRONMENT_PRODUCTION, EnvironmentEnum::fromString('PRODUCTION'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT, EnvironmentEnum::fromString('DEVELOPMENT'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_STAGING, EnvironmentEnum::fromString('StAgInG'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_TESTING, EnvironmentEnum::fromString('TeStInG'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_CUSTOM, EnvironmentEnum::fromString('CuStOm'));
    }

    public function test_fromString_unknownValue_returns_custom(): void
    {
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_CUSTOM, EnvironmentEnum::fromString('unknown'));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_CUSTOM, EnvironmentEnum::fromString(''));
        $this->assertSame(EnvironmentEnum::ENVIRONMENT_CUSTOM, EnvironmentEnum::fromString('not_a_valid_env'));
    }

    public function test_toString(): void
    {
        $this->assertSame('production', EnvironmentEnum::ENVIRONMENT_PRODUCTION->toString());
        $this->assertSame('development', EnvironmentEnum::ENVIRONMENT_DEVELOPMENT->toString());
        $this->assertSame('staging', EnvironmentEnum::ENVIRONMENT_STAGING->toString());
        $this->assertSame('testing', EnvironmentEnum::ENVIRONMENT_TESTING->toString());
        $this->assertSame('custom', EnvironmentEnum::ENVIRONMENT_CUSTOM->toString());
    }
}
