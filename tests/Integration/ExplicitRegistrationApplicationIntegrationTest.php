<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\SystemEventStore;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;
use stdClass;

#[CoversClass(Application::class)]
#[UsesClass(BasicEventDispatcher::class)]
#[UsesClass(SystemEventStore::class)]
final class ExplicitRegistrationApplicationIntegrationTest extends TestCase
{
    public function test_application_inherits_explicit_registration_inspection(): void
    {
        $application = new Application();
        $application->instance('application.service', new stdClass());

        $this->assertTrue($application->hasExplicitRegistration('application.service'));
        $this->assertFalse($application->hasExplicitRegistration(self::class));
    }
}
