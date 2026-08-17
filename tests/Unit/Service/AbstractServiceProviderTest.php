<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Service;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\MockObject\Exception;
use PHPUnit\Framework\TestCase;
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
        $this->assertFalse($provider->isDeferred());
    }

    public function test_minimalProviderIsInstantiableWithoutOverridingIsDeferred(): void
    {
        $provider = new MinimalServiceProvider();
        $this->assertFalse($provider->isDeferred());
    }

    public function test_isDeferredReflectsDeferPropertyByDefault(): void
    {
        $provider = new MinimalServiceProvider();
        $provider->defer = true;
        $this->assertTrue($provider->isDeferred());
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

// A provider implementing only the abstract register() method, relying on
// AbstractServiceProvider's default boot()/provides()/isDeferred().
class MinimalServiceProvider extends AbstractServiceProvider
{
    public function register(
        Application $app
    ): void {
        // No-op.
    }
}
