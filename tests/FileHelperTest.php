<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\helpers\FileHelper;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

class FileHelperTest extends TestCase
{
    /**
     * @return void
     * @throws Throwable
     */
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

    /**
     * @return void
     * @throws Throwable
     */
    public function testAtomicWriteRefusesSymlinkTarget(): void
    {
        $dir = sys_get_temp_dir() . '/rabbitmq_filehelper_' . uniqid('', true);
        $real = $dir . '/real.lock';
        $link = $dir . '/ready.lock';
        FileHelper::ensureDir($dir);
        file_put_contents($real, 'real');

        if (!symlink($real, $link)) {
            FileHelper::removeFileQuietly($real);
            @rmdir($dir);
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Refusing to overwrite symlink');
            FileHelper::atomicWrite($link, 'ready');
        } finally {
            FileHelper::removeFileQuietly($link);
            FileHelper::removeFileQuietly($real);
            @rmdir($dir);
        }
    }

    public function testEnsureDirRefusesSymlinkDirectory(): void
    {
        $base = sys_get_temp_dir() . '/rabbitmq_filehelper_' . uniqid('', true);
        $real = $base . '/real';
        $link = $base . '/link';
        FileHelper::ensureDir($real);

        if (!symlink($real, $link)) {
            @rmdir($real);
            @rmdir($base);
            $this->markTestSkipped('Symlinks are not available in this environment.');
        }

        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Directory path must not be a symlink');
            FileHelper::ensureDir($link);
        } finally {
            FileHelper::removeFileQuietly($link);
            @rmdir($real);
            @rmdir($base);
        }
    }
}
