<?php

namespace illusiard\rabbitmq\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;

class ConsumeControllerTest extends TestCase
{
    public function testConsumeControllerPassesOptionsToService(): void
    {
        $service = new FakeRabbitMqService();
        Yii::$app->set('rabbitmq', $service);

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

        $exitCode = $controller->actionIndex('orders', 'app\\queues\\RabbitMqHandler', 3, 128);

        $this->assertSame(0, $exitCode);
        $this->assertSame(['orders', 'app\\queues\\RabbitMqHandler', 3], $service->consumeArgs);
        $this->assertTrue($service->managedRetry);
        $this->assertSame([
            'maxAttempts' => 2,
            'retryQueues' => [
                ['name' => 'orders.retry.5s', 'ttlMs' => 5000],
            ],
            'deadQueue' => 'orders.dead',
        ], $service->retryPolicy);
        $this->assertFalse($service->consumeFailFast);
        $this->assertSame(['RuntimeException', 'InvalidArgumentException'], $service->fatalExceptionClasses);
        $this->assertSame(['Exception'], $service->recoverableExceptionClasses);
        $this->assertCount(1, $service->consumeMiddlewares);
        $this->assertSame(MemoryLimitMiddleware::class, $service->consumeMiddlewares[0]['class']);
        $this->assertSame(128 * 1024 * 1024, $service->consumeMiddlewares[0]['memoryLimitBytes']);
    }
}

class FakeRabbitMqService extends \yii\base\Component
{
    public array $consumeMiddlewares = [];
    public bool $managedRetry = false;
    public array $retryPolicy = [];
    public bool $consumeFailFast = true;
    public array $fatalExceptionClasses = [];
    public array $recoverableExceptionClasses = [];
    public array $consumeArgs = [];

    public function consume(string $queue, string $handlerFqcn, int $prefetch = 1): void
    {
        $this->consumeArgs = [$queue, $handlerFqcn, $prefetch];
    }

    public function getConnection()
    {
        return new class {
            public function getConsumer()
            {
                return null;
            }
        };
    }
}
