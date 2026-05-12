<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use Yii;
use yii\base\InvalidConfigException;
use yii\caching\ArrayCache;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\definitions\discovery\DiscoveryEngine;
use illusiard\rabbitmq\definitions\discovery\DiscoveryConfig;
use illusiard\rabbitmq\definitions\discovery\DefinitionsDiscovery;
use illusiard\rabbitmq\exceptions\DuplicateDefinitionIdException;

class DefinitionsDiscoveryTest extends TestCase
{
    private ?string $originalAppAlias = null;
    private ?string $tempRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $alias = Yii::getAlias('@app', false);
        $this->originalAppAlias = $alias !== false ? $alias : null;
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    protected function tearDown(): void
    {
        if ($this->tempRoot && is_dir($this->tempRoot)) {
            $this->removeDir($this->tempRoot);
        }

        if ($this->originalAppAlias !== null) {
            Yii::setAlias('@app', $this->originalAppAlias);
        }

        Yii::setAlias('@defs', null);

        if (Yii::$app && Yii::$app->has('cache')) {
            Yii::$app->set('cache', null);
        }

        parent::tearDown();
    }

    public function testFilePathToFqcn(): void
    {
        $root = $this->createTempRoot();
        $path = $root . '/services/rabbitmq/consumers/FooConsumer.php';
        $this->writeFile(
            $path,
            "<?php\n\nnamespace app\\services\\rabbitmq\\consumers;\n\nclass FooConsumer {}\n"
        );

        Yii::setAlias('@app', $root);

        $engine = new DiscoveryEngine();
        $fqcn = $engine->filePathToFqcn($path);

        $this->assertSame('app\\services\\rabbitmq\\consumers\\FooConsumer', $fqcn);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testRegistryScansOnlyGivenPaths(): void
    {
        $root = $this->createTempRoot();
        $consumersPath = $root . '/services/rabbitmq/consumers';
        $otherPath = $root . '/services/rabbitmq/other';

        $this->writeFile(
            $consumersPath . '/EventsConsumer.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class EventsConsumer implements ConsumerInterface
{
    public function getQueue(): string { return 'events'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        $this->writeFile(
            $otherPath . '/HiddenConsumer.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\other;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class HiddenConsumer implements ConsumerInterface
{
    public function getQueue(): string { return 'hidden'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        Yii::setAlias('@defs', $root);

        $config = new DiscoveryConfig([
            'enabled' => true,
            'paths' => ['@defs/services/rabbitmq/consumers'],
            'aliasRoot' => '@defs',
            'baseNamespace' => 'defs',
        ]);
        $discovery = new DefinitionsDiscovery($config);

        $registry = $discovery->discoverConsumers();
        $all = $registry->all();

        $this->assertArrayHasKey('events', $all);
        $this->assertArrayNotHasKey('hidden', $all);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testDuplicateIdThrows(): void
    {
        $this->expectException(DuplicateDefinitionIdException::class);

        $root = $this->createTempRoot();
        $consumersPath = $root . '/services/rabbitmq/consumers';

        $this->writeFile(
            $consumersPath . '/EventsConsumer.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class EventsConsumer implements ConsumerInterface
{
    public function getQueue(): string { return 'events'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        $this->writeFile(
            $consumersPath . '/Events.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class Events implements ConsumerInterface
{
    public function getQueue(): string { return 'events'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        Yii::setAlias('@defs', $root);

        $config = new DiscoveryConfig([
            'enabled' => true,
            'paths' => ['@defs/services/rabbitmq/consumers'],
            'aliasRoot' => '@defs',
            'baseNamespace' => 'defs',
        ]);
        $discovery = new DefinitionsDiscovery($config);
        $discovery->discoverConsumers();
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testMultiPathConfigurationFiltersRegistryTypes(): void
    {
        $root = $this->createTempRoot();
        $base = $root . '/services/rabbitmq';

        $this->writeFile(
            $base . '/consumers/OrdersConsumer.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class OrdersConsumer implements ConsumerInterface
{
    public function getQueue(): string { return 'orders'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        $this->writeFile(
            $base . '/publishers/OrdersPublisher.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\publishers;

use illusiard\rabbitmq\definitions\publisher\PublisherInterface;

class OrdersPublisher implements PublisherInterface
{
    public function getExchange(): string { return 'orders-exchange'; }
    public function getRoutingKey(): string { return 'orders'; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        $this->writeFile(
            $base . '/middlewares/TraceMiddleware.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\middlewares;

use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;

class TraceMiddleware implements MiddlewareInterface
{
    public function process(ConsumeContext $context, callable $next)
    {
        return $next($context);
    }
}
PHP
        );

        $this->writeFile(
            $base . '/handlers/OrdersHandler.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\handlers;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\handler\HandlerInterface;
use illusiard\rabbitmq\message\Envelope;

class OrdersHandler implements HandlerInterface
{
    public function handle(Envelope $envelope): ConsumeResult|bool
    {
        return true;
    }
}
PHP
        );

        Yii::setAlias('@defs', $root);

        $config = new DiscoveryConfig([
            'enabled' => true,
            'paths' => [
                '@defs/services/rabbitmq/consumers',
                '@defs/services/rabbitmq/publishers',
                '@defs/services/rabbitmq/middlewares',
                '@defs/services/rabbitmq/handlers',
            ],
            'aliasRoot' => '@defs',
            'baseNamespace' => 'defs',
        ]);
        $discovery = new DefinitionsDiscovery($config);

        $this->assertArrayHasKey('orders', $discovery->discoverConsumers()->all());
        $this->assertArrayHasKey('orders', $discovery->discoverPublishers()->all());
        $this->assertArrayHasKey('trace', $discovery->discoverMiddlewares()->all());
        $this->assertArrayHasKey('orders', $discovery->discoverHandlers()->all());
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testCacheHitReturnsCachedRegistry(): void
    {
        $root = $this->createTempRoot();
        $consumersPath = $root . '/services/rabbitmq/consumers';

        $this->writeFile(
            $consumersPath . '/CacheConsumer.php',
            <<<'PHP'
<?php

namespace defs\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class CacheConsumer implements ConsumerInterface
{
    public function getQueue(): string { return 'cache'; }
    public function getHandler() { return function () { return true; }; }
    public function getOptions(): array { return []; }
    public function getMiddlewares(): array { return []; }
}
PHP
        );

        Yii::setAlias('@defs', $root);
        Yii::$app?->set('cache', new ArrayCache());

        $config = new DiscoveryConfig([
            'enabled' => true,
            'paths' => ['@defs/services/rabbitmq/consumers'],
            'cache' => 'cache',
            'cacheTtl' => 300,
            'aliasRoot' => '@defs',
            'baseNamespace' => 'defs',
        ]);

        $discovery = new DefinitionsDiscovery($config);
        $registry = $discovery->discoverConsumers();
        $this->assertArrayHasKey('cache', $registry->all());

        @unlink($consumersPath . '/CacheConsumer.php');

        $registryAgain = (new DefinitionsDiscovery($config))->discoverConsumers();
        $this->assertArrayHasKey('cache', $registryAgain->all());
    }

    private function createTempRoot(): string
    {
        $this->tempRoot = sys_get_temp_dir() . '/rabbitmq_discovery_' . uniqid('', true);
        mkdir($this->tempRoot, 0777, true);

        return $this->tempRoot;
    }

    private function writeFile(string $path, string $contents): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents($path, $contents);
    }

    private function removeDir(string $dir): void
    {
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
