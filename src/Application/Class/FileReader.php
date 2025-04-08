<?php

declare(strict_types=1);

namespace DomainFlow\Application\Class;

class FileReader
{
    /**
     * Reads the file content.
     *
     * @param string $file
     * @return string|false
     */
    public function read(string $file): string|false
    {
        return file_get_contents($file);
    }
}
