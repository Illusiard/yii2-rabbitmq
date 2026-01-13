<?php

namespace illusiard\rabbitmq\helpers;

use RuntimeException;

class FileHelper
{
    public static function ensureDir(string $dir): void
    {
        if ($dir === '') {
            throw new RuntimeException('Directory path is empty.');
        }

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0777, true) && !is_dir($dir)) {
                throw new RuntimeException('Failed to create directory: ' . $dir);
            }
        }
    }

    public static function atomicWrite(string $path, string $contents): void
    {
        $dir = dirname($path);
        self::ensureDir($dir);

        $temp = tempnam($dir, 'tmp_');
        if ($temp === false) {
            throw new RuntimeException('Failed to create temp file in: ' . $dir);
        }

        try {
            $bytes = file_put_contents($temp, $contents, LOCK_EX);
            if ($bytes === false) {
                throw new RuntimeException('Failed to write temp file: ' . $temp);
            }

            if (!rename($temp, $path)) {
                throw new RuntimeException('Failed to move temp file to: ' . $path);
            }
        } catch (\Throwable $e) {
            @unlink($temp);
            throw $e;
        }
    }

    public static function removeFileQuietly(string $path): void
    {
        if ($path === '') {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
        }
    }
}
