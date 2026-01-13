<?php

namespace illusiard\rabbitmq\definitions\discovery;

class DiscoveryConfig
{
    private bool $enabled;
    private array $paths;
    private ?string $cacheId;
    private int $cacheTtl;
    private string $aliasRoot;
    private string $baseNamespace;

    public function __construct(array $config)
    {
        $this->enabled = (bool)($config['enabled'] ?? false);
        $this->paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];
        $cache = $config['cache'] ?? null;
        $this->cacheId = is_string($cache) && $cache !== '' ? $cache : null;
        $ttl = $config['cacheTtl'] ?? 300;
        $this->cacheTtl = is_int($ttl) && $ttl >= 0 ? $ttl : 300;
        $this->aliasRoot = is_string($config['aliasRoot'] ?? null) && $config['aliasRoot'] !== ''
            ? $config['aliasRoot']
            : '@app';
        $this->baseNamespace = is_string($config['baseNamespace'] ?? null) && $config['baseNamespace'] !== ''
            ? $config['baseNamespace']
            : 'app';
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getPaths(): array
    {
        return $this->paths;
    }

    public function getCacheId(): ?string
    {
        return $this->cacheId;
    }

    public function getCacheTtl(): int
    {
        return $this->cacheTtl;
    }

    public function getAliasRoot(): string
    {
        return $this->aliasRoot;
    }

    public function getBaseNamespace(): string
    {
        return $this->baseNamespace;
    }

    public function hasHandlersPath(): bool
    {
        foreach ($this->paths as $path) {
            if (!is_string($path)) {
                continue;
            }
            if (str_contains($path, '/handlers') || str_contains($path, '\\handlers')) {
                return true;
            }
        }

        return false;
    }
}
