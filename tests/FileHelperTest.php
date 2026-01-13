<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\helpers\FileHelper;
use PHPUnit\Framework\TestCase;

class FileHelperTest extends TestCase
{
    public function testAtomicWriteCreatesFile(): void
    {
        $dir = sys_get_temp_dir() . '/rabbitmq_filehelper_' . uniqid('', true);
        $path = $dir . '/ready.lock';

        FileHelper::atomicWrite($path, 'ready');

        $this->assertFileExists($path);
        $this->assertSame('ready', file_get_contents($path));

        FileHelper::removeFileQuietly($path);
        @rmdir($dir);
    }

    public function testRemoveFileQuietlyHandlesMissingFile(): void
    {
        $path = sys_get_temp_dir() . '/rabbitmq_missing_' . uniqid('', true) . '.lock';

        FileHelper::removeFileQuietly($path);

        $this->assertFileDoesNotExist($path);
    }
}
