<?php

declare(strict_types=1);

namespace DomainFlow\Tests\Unit\Application\Class;

use DomainFlow\Application\Class\FileReader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileReader::class)]
final class FileReaderTest extends TestCase
{
    private string $file = __DIR__ . "\dummy.txt";

    public function tearDown(): void
    {
        if (file_exists($this->file)) {
            unlink($this->file);
        }
    }

    public function test_read(): void
    {
        $expectedContent = "Lorem Ipsum";
        file_put_contents($this->file, $expectedContent);

        $fileReader = new FileReader();
        $actualContent = $fileReader->read($this->file);

        $this->assertStringContainsString($expectedContent, $actualContent);

    }
}
