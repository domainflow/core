<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Service;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Throwable;

#[CoversClass(AbstractServiceProvider::class)]
class AbstractServiceProviderTest extends TestCase
{
    public function test_providesReturnsEmptyBeforeRegister(): void
    {
        $provider = new DummyServiceProvider();
        $this->assertEquals([], $provider->provides());
    }

    /**
     * @throws Throwable|Exception
     */
    public function test_registerAndProvides(): void
    {
        $provider = new DummyServiceProvider();
        $app = $this->createStub(Application::class);
        $provider->register($app);
        $this->assertEquals(['dummy.service'], $provider->provides());
    }

    /**
     * @throws Throwable|Exception
     */
    public function test_bootDoesNothing(): void
    {
        $provider = new DummyServiceProvider();
        $app = $this->createStub(Application::class);
        $provider->register($app);
        $expected = $provider->provides();
        $provider->boot($app);
        $this->assertEquals($expected, $provider->provides());
    }

    public function test_defaultDeferIsFalse(): void
    {
        $provider = new DummyServiceProvider();
        $reflection = new ReflectionClass($provider);
        $deferProperty = $reflection->getProperty('defer');
        $this->assertFalse($deferProperty->getValue($provider));
    }
}

// dummy class
class DummyServiceProvider extends AbstractServiceProvider
{
    public function register(
        Application $app
    ): void {
        $this->providedServices = ['dummy.service'];
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
