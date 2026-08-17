<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Application\Enum\EnvironmentEnum;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerExceptionInterface;
use Psr\Container\NotFoundExceptionInterface;
use RuntimeException;
use Throwable;

#[CoversNothing]
final class EnvironmentBasedApplicationIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        $logFile = __DIR__ . '/test_logs/app.log';
        if (file_exists($logFile)) {
            unlink($logFile);
        }
        $testLogsDir = __DIR__ . '/test_logs';
        if (is_dir($testLogsDir)) {
            rmdir($testLogsDir);
        }
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|EventManagerException|ContainerExceptionInterface
     */
    public function test_developmentMode(): void
    {
        $app = new Application();
        $app->setEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT);

        # Check if environment is set correctly to development
        $this->assertTrue($app->isEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT));

        ob_start();
        $app->registerProvider(new EnvironmentServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(EnvironmentServiceProvider::class, $providers);

        $userService = $app->get(UserServiceEnv::class);
        $userService->createUser('alice');
        $output = ob_get_clean();

        $this->assertStringContainsString("Registering development services...", $output);
        $this->assertStringContainsString("[DEV] User 'alice' has been created.", $output);
    }

    /**
     * @throws NotFoundExceptionInterface|Throwable|EventManagerException|ContainerExceptionInterface
     */
    public function test_productionMode(): void
    {
        $app = new Application();
        # Check if environment is set automatically to production
        $this->assertTrue($app->isEnvironment(EnvironmentEnum::ENVIRONMENT_PRODUCTION));

        ob_start();

        $app->registerProvider(new EnvironmentServiceProvider());
        $app->boot();

        // Check that application boot process completed successfully
        $this->assertTrue($app->isBooted());

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(EnvironmentServiceProvider::class, $providers);

        $userService = $app->get(UserServiceEnv::class);
        $userService->createUser('bob');
        $output = ob_get_clean();

        $this->assertStringContainsString("Registering production services...", $output);

        $logFile = __DIR__ . '/test_logs/app.log';
        $this->assertFileExists($logFile);
        $logContent = file_get_contents($logFile);
        $this->assertStringContainsString("[PROD] User 'bob' has been created.", $logContent);
    }
}

// dummy classes and interface
interface LoggerInterfaceEnv
{
    public function log(string $message): void;
}

class ConsoleLogger implements LoggerInterfaceEnv
{
    public function log(
        string $message
    ): void {
        echo "[DEV] $message\n";
    }
}

class FileLoggerEnv implements LoggerInterfaceEnv
{
    protected string $logFile;

    public function __construct(
        string $logFile
    ) {
        $this->logFile = $logFile;
        $logDir = dirname($this->logFile);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0777, true);
            echo "Log directory created: $logDir\n";
        }
    }

    public function log(
        string $message
    ): void {
        file_put_contents($this->logFile, "[PROD] $message\n", FILE_APPEND);
    }
}

readonly class UserServiceEnv
{
    public function __construct(
        private LoggerInterfaceEnv $logger
    ) {
    }

    public function createUser(
        string $username
    ): void {
        $this->logger->log("User '$username' has been created.");
    }
}

class EnvironmentServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [LoggerInterfaceEnv::class, UserServiceEnv::class];
    public bool $defer = false;

    public function register(
        Application $app
    ): void {
        if ($app->isEnvironment(EnvironmentEnum::ENVIRONMENT_DEVELOPMENT)) {
            echo "Registering development services...\n";
            $app->bind(LoggerInterfaceEnv::class, fn () => new ConsoleLogger(), true);
        } elseif ($app->isEnvironment(EnvironmentEnum::ENVIRONMENT_PRODUCTION)) {
            echo "Registering production services...\n";
            $logFile = __DIR__ . '/test_logs/app.log';
            $app->bind(LoggerInterfaceEnv::class, fn () => new FileLoggerEnv($logFile), true);
        } else {
            throw new RuntimeException("Unknown environment: " . $app->environment()->toString());
        }

        $app->bind(UserServiceEnv::class, fn (Application $app) => new UserServiceEnv($app->get(LoggerInterfaceEnv::class)), true);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
