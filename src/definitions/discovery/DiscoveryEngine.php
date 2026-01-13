<?php

namespace illusiard\rabbitmq\definitions\discovery;

use Yii;

class DiscoveryEngine
{
    public const CACHE_VERSION = 1;

    public function scanPaths(array $paths): array
    {
        $files = [];

        foreach ($paths as $pathAlias) {
            if (!is_string($pathAlias) || $pathAlias === '') {
                continue;
            }

            $path = Yii::getAlias($pathAlias, false);
            if ($path === false || !is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($iterator as $file) {
                if (!$file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }
                $files[] = $file->getPathname();
            }
        }

        return array_values(array_unique($files));
    }

    public function filePathToFqcn(string $file, string $aliasRoot = '@app', string $baseNs = 'app'): ?string
    {
        $rootPath = Yii::getAlias($aliasRoot, false);
        if ($rootPath === false || !is_dir($rootPath)) {
            return null;
        }

        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $file);
        if (strpos($normalized, $rootPath) !== 0) {
            return null;
        }

        $relative = substr($normalized, strlen($rootPath));
        if ($relative === false || $relative === '') {
            return null;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        if (str_ends_with($relative, '.php')) {
            $relative = substr($relative, 0, -4);
        }

        if ($relative === '') {
            return null;
        }

        return rtrim($baseNs, '\\') . '\\' . $relative;
    }
}
