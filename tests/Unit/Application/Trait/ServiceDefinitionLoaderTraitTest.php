<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use Closure;
use DomainFlow\Application;
use DomainFlow\Application\Class\BasicEventDispatcher;
use DomainFlow\Application\Class\FileReader;
use DomainFlow\Application\Class\SystemEventStore;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\ServiceDefinitionLoaderException;
use Exception;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Yaml\Yaml;
use Throwable;

#[CoversClass(Application::class)]
#[CoversClass(ServiceDefinitionLoaderException::class)]
#[CoversClass(BasicEventDispatcher::class)]
#[CoversClass(SystemEventStore::class)]
final class ServiceDefinitionLoaderTraitTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'service-definitions-test';
        if (!is_dir($this->tempDir)) {
            mkdir($this->tempDir, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->tempDir . DIRECTORY_SEPARATOR . '*'));
        rmdir($this->tempDir);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException|\Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface|Throwable
     */
    public function test_loadValidPhpDefinitions(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.php';

        file_put_contents($file, '<?php return ["TestService" => ["concrete" => "stdClass", "shared" => true]];');

        $app = new Application();
        $app->loadServiceDefinitions($file);

        $this->assertTrue($app->has('TestService'));
        $this->assertInstanceOf(stdClass::class, $app->get('TestService'));
        $this->assertSame($app->get('TestService'), $app->get('TestService'), 'Definition declared shared: true.');
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException|\Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface|Throwable
     */
    public function test_loadValidJsonDefinitions(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';
        $data = [
            "TestService" => ["concrete" => "stdClass", "shared" => false],
        ];
        file_put_contents($file, json_encode($data));

        $app = new Application();

        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents(file_get_contents($file));
        $app->setFileReader($fakeReader);

        $app->loadServiceDefinitions($file);

        $this->assertTrue($app->has('TestService'));
        $this->assertNotSame($app->get('TestService'), $app->get('TestService'), 'Definition declared shared: false.');
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_jsonFileReadFailure(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';
        file_put_contents($file, '{"TestService": {"concrete": "stdClass"}}');

        $app = new Application();
        $fakeReader = new FakeFileReader();

        $fakeReader->setFakeContents(false);
        $app->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to read JSON service definition file: $file");

        $app->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_jsonDecodeError(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';

        file_put_contents($file, '{"TestService": {"concrete": "stdClass"');

        $app = new Application();
        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents(file_get_contents($file));
        $app->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("JSON decode error in file: $file");

        $app->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException|\Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface|Throwable
     */
    public function test_loadValidYamlDefinitions(): void
    {
        if (!class_exists(Yaml::class)) {
            $this->markTestSkipped('Symfony YAML component is not available.');
        }

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        $yamlContent = <<<YAML
            TestService:
              concrete: stdClass
              shared: true
            YAML;
        file_put_contents($file, $yamlContent);

        $app = new Application();
        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents($yamlContent);
        $app->setFileReader($fakeReader);

        $app->loadServiceDefinitions($file);

        $this->assertTrue($app->has('TestService'));
        $this->assertInstanceOf(stdClass::class, $app->get('TestService'));
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_yamlFileReadFailure(): void
    {
        if (!class_exists(Yaml::class)) {
            $this->markTestSkipped('Symfony YAML component is not available.');
        }

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        file_put_contents($file, "TestService:\n  concrete: stdClass");

        $app = new Application();
        $fakeReader = new FakeFileReader();

        $fakeReader->setFakeContents(false);
        $app->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to read YAML service definition file: $file");

        $app->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_unsupportedFileExtension(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.txt';
        file_put_contents($file, "Some content");

        $app = new Application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unsupported file extension: txt");

        $app->loadServiceDefinitions($file);
    }

    public function test_getFileReaderInitializesFileReader(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();
        $reader = $app->exposeGetFileReader();

        $this->assertSame($reader, $app->exposeGetFileReader());
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_loadServiceDefinitionsFileNotFoundUsingVfsStream(): void
    {
        vfsStream::setup('root');
        $nonExistentFile = vfsStream::url('root/nonexistent.php');

        $app = new Application();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Service definition file not found: $nonExistentFile");
        $app->loadServiceDefinitions($nonExistentFile);
    }

    public function test_loadServiceDefinitionsDefinitionNotArrayUsingVfsStream(): void
    {
        vfsStream::setup('root');
        $file = vfsStream::url('root/services.php');

        file_put_contents($file, '<?php return ["TestService" => "not an array"];');

        $app = new Application();

        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Definition is not an array");

        try {
            $app->loadServiceDefinitions($file);
        } catch (ServiceDefinitionLoaderException $e) {
            $this->assertEventFiredWithArgs($app, 'service_definition.error', ['TestService', 'Definition is not an array']);
            throw $e;
        }
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_loadServiceDefinitionsFormatNotArrayUsingVfsStream(): void
    {
        vfsStream::setup('root');
        $file = vfsStream::url('root/services.php');
        file_put_contents($file, '<?php return "invalid format";');

        $app = new Application();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid service definitions format in file: $file");

        $app->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException|\Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface|Throwable
     */
    public function test_processServiceDefinitionWithFactoryClosure(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();
        $factory = function () {
            return 'factory result';
        };
        $definition = [
            'factory' => $factory,
            'shared' => true,
        ];
        $app->exposeProcessServiceDefinition('TestService', $definition);

        $this->assertTrue($app->has('TestService'));
        $this->assertSame('factory result', $app->get('TestService'));
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException|\Psr\Container\ContainerExceptionInterface|\Psr\Container\NotFoundExceptionInterface|Throwable
     */
    public function test_processServiceDefinitionWithFactoryNonClosure(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();

        $definition = [
            'factory' => [$this, 'dummyFactory'],
            'shared' => false,
        ];
        $app->exposeProcessServiceDefinition('TestService', $definition);

        $this->assertTrue($app->has('TestService'));
        $this->assertSame('dummy', $app->get('TestService'));
    }

    public function dummyFactory(): string
    {
        return 'dummy';
    }

    /**
     * @throws EventManagerException
     */
    public function test_processServiceDefinitionCatchBranch(): void
    {
        $app = new class() extends TestableServiceDefinitionLoaderApplication {
            public function bind(
                string $abstract,
                Closure|string|null $concrete = null,
                bool $shared = false
            ): void {
                throw new Exception("Forced bind() failure");
            }
        };

        $definition = [
            'concrete' => 'stdClass',
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: Forced bind() failure");

        $app->exposeProcessServiceDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     */
    public function test_processServiceDefinitionWithInvalidConcrete(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();
        $definition = [
            'concrete' => 123,
            'shared' => false,
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: The concrete definition for service TestService must be a string or Closure.");

        $app->exposeProcessServiceDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     */
    public function test_processServiceDefinitionWithInvalidTag(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();
        $definition = [
            'concrete' => 'stdClass',
            'tags' => [123],
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: Invalid tag type for service TestService. Tag must be a string.");

        $app->exposeProcessServiceDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     * @throws ServiceDefinitionLoaderException
     */
    public function test_processServiceDefinitionWithValidTag(): void
    {
        $app = new TestableServiceDefinitionLoaderApplication();
        $definition = [
            'concrete' => 'stdClass',
            'tags' => ['myTag'],
        ];
        $app->exposeProcessServiceDefinition('TestService', $definition);

        $this->assertArrayHasKey('TestService', $app->getByTag('myTag'));
    }

    /**
     * @param array<int, mixed> $expectedArgs
     */
    private function assertEventFiredWithArgs(
        Application $app,
        string $event,
        array $expectedArgs
    ): void {
        foreach ($app->getEvents()[$event] ?? [] as $entry) {
            if ($entry['args'] === $expectedArgs) {
                return;
            }
        }
        $this->fail(sprintf('Event [%s] with the expected arguments was not fired.', $event));
    }
}

# Dummy classes
class FakeFileReader extends FileReader
{
    private mixed $contents = '';

    /**
     * @param string|false $contents
     */
    public function setFakeContents(
        mixed $contents
    ): void {
        $this->contents = $contents;
    }

    public function read(
        string $file
    ): string|false {
        return $this->contents;
    }
}

class TestableServiceDefinitionLoaderApplication extends Application
{
    public function exposeGetFileReader(): FileReader
    {
        return $this->getFileReader();
    }

    /**
     * @param array<string, mixed> $definition
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function exposeProcessServiceDefinition(
        string $abstract,
        array $definition
    ): void {
        $this->processServiceDefinition($abstract, $definition);
    }
}
