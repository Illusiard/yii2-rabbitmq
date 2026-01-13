<?php

namespace illusiard\rabbitmq\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;

class ConsumeControllerTest extends TestCase
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

    public function testConsumeControllerPassesOptionsToService(): void
    {
        $service = new FakeRabbitMqService();
        Yii::$app->set('rabbitmq', $service);

        $this->prepareAppConsumers();
        $service->discovery = [
            'enabled' => true,
            'paths' => ['@app/services/rabbitmq/consumers'],
        ];

        $controller = new ConsumeController('rabbitmq/consume', Yii::$app);
        $controller->managedRetry = 1;
        $controller->retryPolicy = json_encode([
            'maxAttempts' => 2,
            'retryQueues' => [
                ['name' => 'orders.retry.5s', 'ttlMs' => 5000],
            ],
            'deadQueue' => 'orders.dead',
        ]);
        $controller->consumeFailFast = 0;
        $controller->fatalExceptionClasses = 'RuntimeException,InvalidArgumentException';
        $controller->recoverableExceptionClasses = 'Exception';

        $exitCode = $controller->actionIndex('orders', 128);

        $this->assertSame(0, $exitCode);
        $this->assertSame('orders', $service->lastQueue);
        $this->assertSame(3, $service->lastPrefetch);
        $this->assertTrue($service->handlerCalled);
        $this->assertSame('orders', $service->handlerQueue);
    }

    private function prepareAppConsumers(): void
    {
        $this->tempRoot = sys_get_temp_dir() . '/rabbitmq_test_' . uniqid();
        $consumerDir = $this->tempRoot . '/services/rabbitmq/consumers';
        $handlerDir = $this->tempRoot . '/queues';

        mkdir($consumerDir, 0777, true);
        mkdir($handlerDir, 0777, true);

        file_put_contents(
            $handlerDir . '/RabbitMqHandler.php',
            "<?php\n\nnamespace app\\queues;\n\nclass RabbitMqHandler\n{\n    public function __invoke(string \$body, array \$meta): bool\n    {\n        return true;\n    }\n}\n"
        );

        $consumerTemplate = <<<'PHP'
<?php

namespace app\services\rabbitmq\consumers;

use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

class OrdersConsumer implements ConsumerInterface
{
    public function getQueue(): string
    {
        return 'orders';
    }

    public function getHandler()
    {
        return \app\queues\RabbitMqHandler::class;
    }

    public function getOptions(): array
    {
        return [
            'prefetch' => 3,
            'consumeMiddlewares' => [
                ['class' => '%s', 'memoryLimitBytes' => 1024],
            ],
        ];
    }

    public function getMiddlewares(): array
    {
        return [];
    }
}
PHP
        ;

        file_put_contents(
            $consumerDir . '/OrdersConsumer.php',
            sprintf($consumerTemplate, MemoryLimitMiddleware::class)
        );

        Yii::setAlias('@app', $this->tempRoot);
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

class FakeRabbitMqService extends \illusiard\rabbitmq\components\RabbitMqService
{
    public ?string $lastQueue = null;
    public ?int $lastPrefetch = null;
    public bool $handlerCalled = false;
    public ?string $handlerQueue = null;

    public function getConnection(): \illusiard\rabbitmq\contracts\ConnectionInterface
    {
        $parent = $this;
        return new class ($parent) implements \illusiard\rabbitmq\contracts\ConnectionInterface {
            private FakeRabbitMqService $parent;

            public function __construct(FakeRabbitMqService $parent)
            {
                $this->parent = $parent;
            }

            public function connect(): void
            {
            }

            public function isConnected(): bool
            {
                return false;
            }

            public function close(): void
            {
            }

            public function getPublisher(): \illusiard\rabbitmq\contracts\PublisherInterface
            {
                throw new \RuntimeException('Not used in tests.');
            }

            public function getConsumer(): \illusiard\rabbitmq\contracts\ConsumerInterface
            {
                $parent = $this->parent;
                return new class ($parent) implements \illusiard\rabbitmq\contracts\ConsumerInterface {
                    private FakeRabbitMqService $parent;

                    public function __construct(FakeRabbitMqService $parent)
                    {
                        $this->parent = $parent;
                    }

                    public function consume(string $queue, callable $handler, int $prefetch = 1): void
                    {
                        $this->parent->lastQueue = $queue;
                        $this->parent->lastPrefetch = $prefetch;
                        $handler('body', [
                            'body' => 'body',
                            'headers' => [],
                            'properties' => [],
                            'delivery_tag' => 1,
                            'routing_key' => 'routing',
                            'exchange' => 'exchange',
                            'redelivered' => false,
                        ]);
                        $this->parent->handlerCalled = true;
                        $this->parent->handlerQueue = $queue;
                    }
                };
            }
        };
    }
}
