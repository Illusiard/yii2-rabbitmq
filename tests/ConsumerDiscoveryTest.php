<?php

namespace illusiard\rabbitmq\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\consumer\ConsumerDiscovery;
use illusiard\rabbitmq\exceptions\DuplicateConsumerIdException;

class ConsumerDiscoveryTest extends TestCase
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

        parent::tearDown();
    }

    public function testDiscoveryFindsConsumers(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/rabbitmq_discovery_' . uniqid();
        Yii::setAlias('@app', $this->tempRoot);

        $baseDir = $this->tempRoot . '/services/rabbitmq/consumers';
        $this->writeConsumer($baseDir . '/EventsConsumer.php', 'app\\services\\rabbitmq\\consumers', 'EventsConsumer');
        $this->writeConsumer($baseDir . '/AuditLogConsumer.php', 'app\\services\\rabbitmq\\consumers', 'AuditLogConsumer');
        $this->writeConsumer($baseDir . '/Foo.php', 'app\\services\\rabbitmq\\consumers', 'Foo');

        $discovery = new ConsumerDiscovery([
            'enabled' => true,
            'paths' => ['@app/services/rabbitmq/consumers'],
        ]);

        $registry = $discovery->discover();
        $consumers = $registry->all();
        ksort($consumers);

        $this->assertSame([
            'audit-log' => 'app\\services\\rabbitmq\\consumers\\AuditLogConsumer',
            'events' => 'app\\services\\rabbitmq\\consumers\\EventsConsumer',
            'foo' => 'app\\services\\rabbitmq\\consumers\\Foo',
        ], $consumers);
    }

    public function testDiscoveryThrowsOnDuplicateIds(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/rabbitmq_duplicate_' . uniqid();
        Yii::setAlias('@app', $this->tempRoot);

        $baseDir = $this->tempRoot . '/services/rabbitmq/consumers';
        $this->writeConsumer($baseDir . '/a/EventsConsumer.php', 'app\\services\\rabbitmq\\consumers\\a', 'EventsConsumer');
        $this->writeConsumer($baseDir . '/b/EventsConsumer.php', 'app\\services\\rabbitmq\\consumers\\b', 'EventsConsumer');

        $discovery = new ConsumerDiscovery([
            'enabled' => true,
            'paths' => ['@app/services/rabbitmq/consumers'],
        ]);

        $this->expectException(DuplicateConsumerIdException::class);
        $discovery->discover();
    }

    private function writeConsumer(string $path, string $namespace, string $className): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        file_put_contents(
            $path,
            "<?php\n\nnamespace {$namespace};\n\nuse illusiard\\rabbitmq\\consumer\\ConsumerInterface;\n\nclass {$className} implements ConsumerInterface\n{\n    public function queue(): string\n    {\n        return 'queue';\n    }\n\n    public function handler()\n    {\n        return function () {\n            return true;\n        };\n    }\n\n    public function options(): array\n    {\n        return [];\n    }\n}\n"
        );
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
