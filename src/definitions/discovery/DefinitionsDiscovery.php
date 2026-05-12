<?php

namespace illusiard\rabbitmq\definitions\discovery;

use JsonException;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\CacheInterface;
use illusiard\rabbitmq\definitions\registry\ConsumerRegistry;
use illusiard\rabbitmq\definitions\registry\PublisherRegistry;
use illusiard\rabbitmq\definitions\registry\MiddlewareRegistry;
use illusiard\rabbitmq\definitions\registry\HandlerRegistry;
use illusiard\rabbitmq\definitions\registry\DefinitionRegistry;

class DefinitionsDiscovery
{
    private DiscoveryConfig $config;
    private DiscoveryEngine $engine;

    public function __construct(DiscoveryConfig $config, ?DiscoveryEngine $engine = null)
    {
        $this->config = $config;
        $this->engine = $engine ?? new DiscoveryEngine();
    }

    /**
     * @return ConsumerRegistry
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function discoverConsumers(): ConsumerRegistry
    {
        return $this->discoverRegistry(new ConsumerRegistry(), 'consumers');
    }

    /**
     * @return PublisherRegistry
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function discoverPublishers(): PublisherRegistry
    {
        return $this->discoverRegistry(new PublisherRegistry(), 'publishers');
    }

    /**
     * @return MiddlewareRegistry
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function discoverMiddlewares(): MiddlewareRegistry
    {
        return $this->discoverRegistry(new MiddlewareRegistry(), 'middlewares');
    }

    /**
     * @return HandlerRegistry
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function discoverHandlers(): HandlerRegistry
    {
        return $this->discoverRegistry(new HandlerRegistry(), 'handlers');
    }

    /**
     * @param DefinitionRegistry $registry
     * @param string $type
     * @return DefinitionRegistry
     * @throws InvalidConfigException
     * @throws JsonException
     */
    private function discoverRegistry(DefinitionRegistry $registry, string $type): DefinitionRegistry
    {
        $paths = $this->config->getPaths();
        if (empty($paths)) {
            return $registry;
        }

        $cache = $this->resolveCache();
        $cacheKey = $this->buildCacheKey($type, $paths);
        if ($cache instanceof CacheInterface) {
            $cached = $cache->get($cacheKey);
            if (is_array($cached)) {
                return new ($registry::class)($cached);
            }
        }

        $files = $this->engine->scanPaths($paths);
        foreach ($files as $file) {
            $fqcn = $this->engine->filePathToFqcn(
                $file,
                $this->config->getAliasRoot(),
                $this->config->getBaseNamespace()
            );
            if ($fqcn === null || !class_exists($fqcn)) {
                continue;
            }

            if (!$registry->accepts($fqcn)) {
                continue;
            }

            $registry->register($fqcn);
        }

        if ($cache instanceof CacheInterface) {
            $cache->set($cacheKey, $registry->all(), $this->config->getCacheTtl());
        }

        return $registry;
    }

    /**
     * @return ?CacheInterface
     * @throws InvalidConfigException
     */
    private function resolveCache(): ?CacheInterface
    {
        $cacheId = $this->config->getCacheId();
        if ($cacheId === null || $cacheId === '') {
            return null;
        }

        if (!Yii::$app || !Yii::$app->has($cacheId)) {
            return null;
        }

        $cache = Yii::$app->get($cacheId);
        return $cache instanceof CacheInterface ? $cache : null;
    }

    /**
     * @param string $type
     * @param array $paths
     * @return string
     * @throws JsonException
     */
    private function buildCacheKey(string $type, array $paths): string
    {
        $payload = [
            'type' => $type,
            'paths' => array_values($paths),
            'version' => DiscoveryEngine::CACHE_VERSION,
        ];

        return 'rabbitmq.definitions.' . sha1(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
