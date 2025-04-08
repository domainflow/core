<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Trait;

use Closure;
use DomainFlow\Application;
use DomainFlow\Application\Class\FileReader;
use DomainFlow\Application\Exception\EventManagerException;
use DomainFlow\Application\Exception\ServiceDefinitionLoaderException;
use DomainFlow\Application\Traits\ServiceDefinitionLoaderTrait;
use Exception;
use org\bovigo\vfs\vfsStream;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

#[CoversClass(Application::class)]
#[CoversClass(ServiceDefinitionLoaderException::class)]
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
     * @throws EventManagerException| ServiceDefinitionLoaderException
     */
    public function test_loadValidPhpDefinitions(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.php';

        file_put_contents($file, '<?php return ["TestService" => ["concrete" => "SomeClass", "shared" => true]];');

        $loader = new DummyServiceDefinitionLoader();
        $loader->loadServiceDefinitions($file);

        $this->assertArrayHasKey('TestService', $loader->bindings);
        $this->assertTrue($loader->bindings['TestService']['shared']);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_loadValidJsonDefinitions(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';
        $data = [
            "TestService" => ["concrete" => "SomeClass", "shared" => false],
        ];
        file_put_contents($file, json_encode($data));

        $loader = new DummyServiceDefinitionLoader();

        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents(file_get_contents($file));
        $loader->setFileReader($fakeReader);

        $loader->loadServiceDefinitions($file);

        $this->assertArrayHasKey('TestService', $loader->bindings);
        $this->assertFalse($loader->bindings['TestService']['shared']);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_jsonFileReadFailure(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';
        file_put_contents($file, '{"TestService": {"concrete": "SomeClass"}}');

        $loader = new DummyServiceDefinitionLoader();
        $fakeReader = new FakeFileReader();

        $fakeReader->setFakeContents(false);
        $loader->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to read JSON service definition file: $file");

        $loader->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_jsonDecodeError(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.json';

        file_put_contents($file, '{"TestService": {"concrete": "SomeClass"');

        $loader = new DummyServiceDefinitionLoader();
        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents(file_get_contents($file));
        $loader->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("JSON decode error in file: $file");

        $loader->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_loadValidYamlDefinitions(): void
    {
        if (!class_exists(Yaml::class)) {
            $this->markTestSkipped('Symfony YAML component is not available.');
        }

        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.yaml';
        $yamlContent = <<<YAML
            TestService:
              concrete: SomeClass
              shared: true
            YAML;
        file_put_contents($file, $yamlContent);

        $loader = new DummyServiceDefinitionLoader();
        $fakeReader = new FakeFileReader();
        $fakeReader->setFakeContents($yamlContent);
        $loader->setFileReader($fakeReader);

        $loader->loadServiceDefinitions($file);

        $this->assertArrayHasKey('TestService', $loader->bindings);
        $this->assertTrue($loader->bindings['TestService']['shared']);
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
        file_put_contents($file, "TestService:\n  concrete: SomeClass");

        $loader = new DummyServiceDefinitionLoader();
        $fakeReader = new FakeFileReader();

        $fakeReader->setFakeContents(false);
        $loader->setFileReader($fakeReader);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Failed to read YAML service definition file: $file");

        $loader->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_unsupportedFileExtension(): void
    {
        $file = $this->tempDir . DIRECTORY_SEPARATOR . 'services.txt';
        file_put_contents($file, "Some content");

        $loader = new DummyServiceDefinitionLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Unsupported file extension: txt");

        $loader->loadServiceDefinitions($file);
    }

    public function test_getFileReaderInitializesFileReader(): void
    {
        $loader = new DummyServiceDefinitionLoader();
        $reader = $loader->getFileReaderInstance();

        $this->assertSame($reader, $loader->getFileReaderInstance());
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_loadServiceDefinitionsFileNotFoundUsingVfsStream(): void
    {
        vfsStream::setup('root');
        $nonExistentFile = vfsStream::url('root/nonexistent.php');

        $loader = new DummyServiceDefinitionLoader();
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Service definition file not found: $nonExistentFile");
        $loader->loadServiceDefinitions($nonExistentFile);
    }

    public function test_loadServiceDefinitionsDefinitionNotArrayUsingVfsStream(): void
    {
        vfsStream::setup('root');
        $file = vfsStream::url('root/services.php');

        file_put_contents($file, '<?php return ["TestService" => "not an array"];');

        $loader = new DummyServiceDefinitionLoader();

        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Definition is not an array");

        try {
            $loader->loadServiceDefinitions($file);
        } catch (ServiceDefinitionLoaderException $e) {

            $errorFound = false;
            foreach ($loader->events as $event) {
                if ($event['event'] === 'service_definition.error') {
                    $errorFound = true;
                    $this->assertEquals('TestService', $event['args'][0]);
                    $this->assertEquals("Definition is not an array", $event['args'][1]);
                    break;
                }
            }
            $this->assertTrue($errorFound, "Expected error event was not fired.");
            throw $e;
        } catch (EventManagerException $e) {
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

        $loader = new DummyServiceDefinitionLoader();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Invalid service definitions format in file: $file");

        $loader->loadServiceDefinitions($file);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_processServiceDefinitionWithFactoryClosure(): void
    {
        $loader = new DummyServiceDefinitionLoader();
        $factory = function () {
            return 'factory result';
        };
        $definition = [
            'factory' => $factory,
            'shared' => true,
        ];
        $loader->testProcessDefinition('TestService', $definition);

        $this->assertArrayHasKey('TestService', $loader->bindings);
        $this->assertSame($factory, $loader->bindings['TestService']['concrete']);
        $this->assertTrue($loader->bindings['TestService']['shared']);
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function test_processServiceDefinitionWithFactoryNonClosure(): void
    {
        $loader = new DummyServiceDefinitionLoader();

        $definition = [
            'factory' => [$this, 'dummyFactory'],
            'shared' => false,
        ];
        $loader->testProcessDefinition('TestService', $definition);

        $this->assertArrayHasKey('TestService', $loader->bindings);
        $this->assertInstanceOf(Closure::class, $loader->bindings['TestService']['concrete']);
        $this->assertFalse($loader->bindings['TestService']['shared']);
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
        $loader = new class() extends DummyServiceDefinitionLoader {
            protected function bind(
                string $abstract,
                $concrete,
                bool $shared = false
            ): void {
                throw new Exception("Forced bind() failure");
            }
        };

        $definition = [
            'concrete' => 'SomeClass',
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: Forced bind() failure");

        $loader->testProcessDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     */
    public function test_processServiceDefinitionWithInvalidConcrete(): void
    {
        $loader = new DummyServiceDefinitionLoader();
        $definition = [
            'concrete' => 123,
            'shared' => false,
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: The concrete definition for service TestService must be a string or Closure.");

        $loader->testProcessDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     */
    public function test_processServiceDefinitionWithInvalidTag(): void
    {
        $loader = new DummyServiceDefinitionLoader();
        $definition = [
            'concrete' => 'SomeClass',
            'tags' => [123],
        ];
        $this->expectException(ServiceDefinitionLoaderException::class);
        $this->expectExceptionMessage("Failed to process service definition for [TestService]: Invalid tag type for service TestService. Tag must be a string.");

        $loader->testProcessDefinition('TestService', $definition);
    }

    /**
     * @throws EventManagerException
     * @throws ServiceDefinitionLoaderException
     */
    public function test_processServiceDefinitionWithValidTag(): void
    {
        $loader = new DummyServiceDefinitionLoader();
        $definition = [
            'concrete' => 'SomeClass',
            'tags' => ['myTag'],
        ];
        $loader->testProcessDefinition('TestService', $definition);

        $this->assertArrayHasKey('myTag', $loader->tags);
        $this->assertEquals(['TestService'], $loader->tags['myTag']);
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

class DummyServiceDefinitionLoader
{
    use ServiceDefinitionLoaderTrait;

    public array $bindings = [];
    public array $tags = [];
    public array $events = [];

    protected function bind(
        string $abstract,
        $concrete,
        bool $shared = false
    ): void {
        $this->bindings[$abstract] = ['concrete' => $concrete, 'shared' => $shared];
    }

    protected function tag(
        string $tag,
        array $services
    ): void {
        $this->tags[$tag] = $services;
    }

    protected function fireEvent(
        string $event,
        ...$args
    ): void {
        $this->events[] = ['event' => $event, 'args' => $args];
    }

    public function getFileReaderInstance(): FileReader
    {
        return $this->getFileReader();
    }

    /**
     * @throws EventManagerException|ServiceDefinitionLoaderException
     */
    public function testProcessDefinition(
        string $abstract,
        array $definition
    ): void {
        $this->processServiceDefinition($abstract, $definition);
    }

}
