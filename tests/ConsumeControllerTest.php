<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use Yii;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;
use illusiard\rabbitmq\tests\fixtures\ConsumeControllerRabbitMqService;
use yii\base\InvalidConfigException;

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

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testConsumeControllerPassesOptionsToService(): void
    {
        $service = new ConsumeControllerRabbitMqService();
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
        ], JSON_THROW_ON_ERROR);
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
        $this->tempRoot = sys_get_temp_dir() . '/rabbitmq_test_' . uniqid('', true);
        $consumerDir = $this->tempRoot . '/services/rabbitmq/consumers';
        $handlerDir = $this->tempRoot . '/queues';

        mkdir($consumerDir, 0777, true);
        mkdir($handlerDir, 0777, true);

        file_put_contents(
            $handlerDir . '/RabbitMqHandler.php',
            "<?php\n\nnamespace app\\queues;\n\nuse illusiard\\rabbitmq\\definitions\\handler\\HandlerInterface;\nuse illusiard\\rabbitmq\\message\\Envelope;\n\nclass RabbitMqHandler implements HandlerInterface\n{\n    public function handle(Envelope \$envelope): bool\n    {\n        return true;\n    }\n}\n"
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
