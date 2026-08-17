<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use Throwable;

#[CoversNothing]
class DependencyInversionApplicationTest extends TestCase
{
    /**
     * @throws Throwable|NotFoundExceptionInterface| ContainerExceptionInterface
     */
    public function test_databaseUserServiceRegistrationAndLogging(): void
    {
        $app = new Application();
        $app->registerProvider(new DatabaseUserServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(DatabaseUserServiceProvider::class, $providers);

        ob_start();

        $userService = $app->get(UserService::class);
        $userService->registerUser('jane_doe');
        $output = ob_get_clean();

        # Verify the output
        $expectedOutput = "DatabaseLogger: User 'jane_doe' registered successfully.\n";
        $this->assertEquals($expectedOutput, $output);
    }

    /**
     * @throws Throwable|NotFoundExceptionInterface| ContainerExceptionInterface
     */
    public function test_filesystemUserServiceRegistrationAndLogging(): void
    {
        $app = new Application();
        $app->registerProvider(new FileLoggerUserServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Verify that provider is not registered yet (before calling get())
        $providers = $app->getProviders();
        $this->assertArrayNotHasKey(FileLoggerUserServiceProvider::class, $providers);

        ob_start();
        $userService = $app->get(UserService::class);
        $userService->registerUser('jane_doe');
        $output = ob_get_clean();

        // provider is now registered after calling get()
        $providers = $app->getProviders();
        $this->assertArrayHasKey(FileLoggerUserServiceProvider::class, $providers);

        // Verify the output
        $expectedOutput = "FileLogger: User 'jane_doe' registered successfully.\n";
        $this->assertEquals($expectedOutput, $output);
    }
}

# dummy classes and interface
interface LoggerInterface
{
    public function log(string $message): void;
}

class FileLogger implements LoggerInterface
{
    public function log(
        string $message
    ): void {
        echo "FileLogger: $message\n";
    }
}

class DatabaseLogger implements LoggerInterface
{
    public function log(
        string $message
    ): void {
        echo "DatabaseLogger: $message\n";
    }
}

class UserService
{
    private LoggerInterface $logger;

    public function __construct(
        LoggerInterface $logger
    ) {
        $this->logger = $logger;
    }

    public function registerUser(
        string $username
    ): void {
        $this->logger->log("User '$username' registered successfully.");
    }
}

class DatabaseUserServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [UserService::class, LoggerInterface::class];
    public bool $defer = false;

    public function register(
        Application $app
    ): void {
        $app->bind(
            LoggerInterface::class,
            fn () => new DatabaseLogger(),
            true
        );
        $app->bind(UserService::class, fn (Application $app) => new UserService(
            $app->get(LoggerInterface::class)
        ), true);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}

class FileLoggerUserServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [UserService::class, LoggerInterface::class];
    public bool $defer = true;

    public function register(
        Application $app
    ): void {
        $app->bind(
            LoggerInterface::class,
            fn () => new FileLogger(),
            true
        );
        $app->bind(UserService::class, fn (Application $app) => new UserService(
            $app->get(LoggerInterface::class)
        ), true);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
