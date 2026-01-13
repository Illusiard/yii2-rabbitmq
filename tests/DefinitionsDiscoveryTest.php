<?php

namespace illusiard\rabbitmq\tests;

use Yii;
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
        $fqcn = $engine->filePathToFqcn($path, '@app', 'app');

        $this->assertSame('app\\services\\rabbitmq\\consumers\\FooConsumer', $fqcn);
    }

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
        if (Yii::$app) {
            Yii::$app->set('cache', new ArrayCache());
        }

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
