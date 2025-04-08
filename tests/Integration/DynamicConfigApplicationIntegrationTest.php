<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Integration;

use DomainFlow\Application;
use DomainFlow\Service\AbstractServiceProvider;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;
use Throwable;

#[CoversNothing]
final class DynamicConfigApplicationIntegrationTest extends TestCase
{
    private string $configFile;

    protected function setUp(): void
    {
        $this->configFile = __DIR__ . '/services.php';
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    protected function tearDown(): void
    {
        if (file_exists($this->configFile)) {
            unlink($this->configFile);
        }
    }

    /**
     * @throws Throwable
     */
    public function test_notificationService(): void
    {
        $app = new Application();
        $app->registerProvider(new ConfigServiceProvider());
        $app->boot();

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(ConfigServiceProvider::class, $providers);

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertEmpty($cache);

        ob_start();
        $notificationService = $app->get(NotificationService::class);
        $notificationService->notify("New user registered!");
        $output = ob_get_clean();

        # Verify the output
        $expectedOutput = "EmailService: Sending message - Notification: New user registered!\n";
        $this->assertEquals($expectedOutput, $output);

        // Check if resolved services cache is populated
        $cache = $app->getResolvedServicesCache();
        $this->assertNotEmpty($cache);
        $this->assertArrayHasKey(NotificationService::class, $cache);
    }

    /**
     * @throws Throwable
     */
    public function test_emailServiceManualExecution(): void
    {
        $app = new Application();
        $app->registerProvider(new ConfigServiceProvider());
        $app->boot();

        // Check if correct provider is registered
        $providers = $app->getProviders();
        $this->assertArrayHasKey(ConfigServiceProvider::class, $providers);

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertEmpty($cache);

        # Verify no cached services yet
        $cache = $app->getResolvedServicesCache();
        $this->assertEmpty($cache);

        ob_start();
        $emailService = $app->get(EmailService::class);
        $emailService->send("Message from manual execution.");

        $output = ob_get_clean();

        # verify the output
        $expectedOutput = "EmailService: Sending message - Message from manual execution.\n";
        $this->assertEquals($expectedOutput, $output);

        // Check if resolved services cache is populated
        $cache = $app->getResolvedServicesCache();
        $this->assertNotEmpty($cache);
        $this->assertArrayHasKey(EmailService::class, $cache);
    }
}

# dummy classes
class EmailService
{
    public function send(
        string $message
    ): void {
        echo "EmailService: Sending message - $message\n";
    }
}

readonly class NotificationService
{
    public function __construct(
        private EmailService $emailService
    ) {
    }

    public function notify(
        string $message
    ): void {
        $this->emailService->send("Notification: $message");
    }
}

class ConfigServiceProvider extends AbstractServiceProvider
{
    protected array $providedServices = [EmailService::class, NotificationService::class];
    public bool $defer = false;

    public function register(
        Application $app
    ): void {
        $configFile = __DIR__ . '/services.php';
        $configContent = <<<'PHP'
            <?php return [
                'EmailService' => [
                    'concrete' => \DomainFlow\Tests\Integration\EmailService::class,
                    'shared' => true,
                ],
                'NotificationService' => [
                    'factory' => function ($app) {
                        return new \DomainFlow\Tests\Integration\NotificationService(
                            $app->get(\DomainFlow\Tests\Integration\EmailService::class)
                        );
                    },
                    'shared' => true,
                ],
            ];
            PHP;

        file_put_contents($configFile, $configContent);
        $app->loadServiceDefinitions($configFile);
    }

    public function isDeferred(): bool
    {
        return $this->defer;
    }
}
