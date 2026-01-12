<?php

namespace illusiard\rabbitmq\consumer;

use Yii;
use yii\caching\CacheInterface;

class ConsumerDiscovery
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function discover(): ConsumerRegistry
    {
        $registry = new ConsumerRegistry();

        if (!$this->isEnabled()) {
            return $registry;
        }

        $paths = $this->config['paths'] ?? [];
        if (!is_array($paths) || empty($paths)) {
            return $registry;
        }

        $cache = $this->resolveCache();
        $cacheKey = $this->buildCacheKey($paths);
        if ($cache instanceof CacheInterface) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)) {
                return new ConsumerRegistry($cached);
            }
        }

        foreach ($paths as $pathAlias) {
            $this->discoverPath($pathAlias, $registry);
        }

        if ($cache instanceof CacheInterface) {
            $cache->set($cacheKey, $registry->all(), $this->getCacheTtl());
        }

        return $registry;
    }

    private function isEnabled(): bool
    {
        return (bool)($this->config['enabled'] ?? false);
    }

    private function discoverPath(string $pathAlias, ConsumerRegistry $registry): void
    {
        $path = Yii::getAlias($pathAlias, false);
        if ($path === false || !is_dir($path)) {
            return;
        }

        $rootAlias = $this->extractAliasRoot($pathAlias);
        $rootPath = Yii::getAlias($rootAlias, false);
        if ($rootPath === false || !is_dir($rootPath)) {
            return;
        }

        $rootPath = rtrim($rootPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            if ($file->getExtension() !== 'php') {
                continue;
            }

            $fqcn = $this->pathToFqcn($file->getPathname(), $rootPath);
            if ($fqcn === null) {
                continue;
            }

            if (!class_exists($fqcn)) {
                continue;
            }

            if (!is_subclass_of($fqcn, ConsumerInterface::class)) {
                continue;
            }

            $registry->register($fqcn);
        }
    }

    private function pathToFqcn(string $filePath, string $rootPath): ?string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $filePath);
        if (strpos($normalized, $rootPath) !== 0) {
            return null;
        }

        $relative = substr($normalized, strlen($rootPath));
        if ($relative === false) {
            return null;
        }

        $relative = str_replace(DIRECTORY_SEPARATOR, '\\', $relative);
        if (str_ends_with($relative, '.php')) {
            $relative = substr($relative, 0, -4);
        }

        return 'app\\' . $relative;
    }

    private function extractAliasRoot(string $alias): string
    {
        if ($alias === '' || $alias[0] !== '@') {
            return '@app';
        }

        $posSlash = strpos($alias, '/');
        $posBackslash = strpos($alias, '\\');

        if ($posSlash === false && $posBackslash === false) {
            return $alias;
        }

        if ($posSlash === false) {
            return substr($alias, 0, $posBackslash);
        }

        if ($posBackslash === false) {
            return substr($alias, 0, $posSlash);
        }

        return substr($alias, 0, min($posSlash, $posBackslash));
    }

    private function resolveCache(): ?CacheInterface
    {
        $cacheId = $this->config['cache'] ?? null;
        if (!is_string($cacheId) || $cacheId === '') {
            return null;
        }

        if (!Yii::$app || !Yii::$app->has($cacheId)) {
            return null;
        }

        $cache = Yii::$app->get($cacheId);
        return $cache instanceof CacheInterface ? $cache : null;
    }

    private function getCacheTtl(): int
    {
        $ttl = $this->config['cacheTtl'] ?? 300;
        return is_int($ttl) && $ttl >= 0 ? $ttl : 300;
    }

    private function buildCacheKey(array $paths): string
    {
        return 'rabbitmq.consumerDiscovery.' . sha1(json_encode(array_values($paths)));
    }
}
