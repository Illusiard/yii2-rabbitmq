<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consumer\RuntimeConsumer;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RecoverableException;
use illusiard\rabbitmq\tests\fixtures\NonHandlerInvokable;
use illusiard\rabbitmq\tests\fixtures\RunnerDecodeRetryRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerLockRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerMiddlewareRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerRecordingRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerRecoverableRetryRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\TestRunnerUserMiddleware;
use Yii;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use PHPUnit\Framework\TestCase;
use yii\base\InvalidConfigException;

class ConsumeRunnerTest extends TestCase
{
    private ?string $originalRuntimeAlias = null;

    protected function setUp(): void
    {
        parent::setUp();
        $runtime = Yii::getAlias('@runtime', false);
        $this->originalRuntimeAlias = is_string($runtime) ? $runtime : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRuntimeAlias !== null) {
            Yii::setAlias('@runtime', $this->originalRuntimeAlias);
        }

        parent::tearDown();
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testRunCreatesAndRemovesLockFileOnFailure(): void
    {
        $lockFile = sys_get_temp_dir() . '/rabbitmq_runner_' . uniqid('', true) . '.lock';
        @unlink($lockFile);

        $service = new RunnerLockRabbitMqService($lockFile, 'Lock file missing.');

        $runner = new ConsumeRunner($service);
        $options = new RunnerOptions($lockFile, null, 'consumer-id');

        $exitCode = $runner->run('queue', function (): bool {
            return true;
        }, [], $options);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($lockFile);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testRunUsesDefaultRuntimeLockFile(): void
    {
        $runtime = sys_get_temp_dir() . '/rabbitmq_runtime_' . uniqid('', true);
        Yii::setAlias('@runtime', $runtime);
        $lockFile = $runtime . '/rabbitmq/consumer-id.lock';

        $service = new RunnerLockRabbitMqService($lockFile, 'Default lock file missing.');

        $runner = new ConsumeRunner($service);
        $options = new RunnerOptions(null, null, 'consumer-id');

        $exitCode = $runner->run('queue', function (): bool {
            return true;
        }, [], $options);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($lockFile);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testStringHandlerMustImplementHandlerInterface(): void
    {
        $service = new RunnerRecordingRabbitMqService();

        $runner = new ConsumeRunner($service);
        $exitCode = $runner->run('queue', NonHandlerInvokable::class);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($service->consumeCalled);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testMiddlewareOrderIsSystemBeforeUserSystemAfter(): void
    {
        TestRunnerUserMiddleware::$actions = [];

        $service = new RunnerMiddlewareRabbitMqService();

        $runner = new ConsumeRunner($service);
        $exitCode = $runner->run('queue', function (): ConsumeResult {
            TestRunnerUserMiddleware::$actions[] = 'handler';
            return ConsumeResult::retry();
        }, [
            'managedRetry' => false,
            'consumeMiddlewares' => [
                TestRunnerUserMiddleware::class,
            ],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'user-before',
            'handler',
            'user-after-retry',
        ], TestRunnerUserMiddleware::$actions);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testRecoverableExceptionIsPassedThroughManagedRetryPolicy(): void
    {
        $service = new RunnerRecoverableRetryRabbitMqService([
            'topology' => [
                'options' => [
                    'retryExchange' => 'topology-retry-ex',
                ],
            ],
        ]);
        $runner = new ConsumeRunner($service);

        $exitCode = $runner->run('queue', function (): void {
            throw new RecoverableException('temporary failure', ErrorCode::CONSUME_FAILED);
        }, [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryExchange' => 'runtime-retry-ex',
                'retryQueues' => [
                    ['name' => 'queue.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'queue.dead',
            ],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $service->published);
        $this->assertSame('topology-retry-ex', $service->published[0]['exchange']);
        $this->assertSame('queue.retry.1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testComponentLevelRetryConfigIsMergedIntoRunnerOptions(): void
    {
        $service = new RunnerRecoverableRetryRabbitMqService([
            'managedRetry' => true,
            'retryPolicy' => [
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => 'component.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'component.dead',
            ],
        ]);
        $runner = new ConsumeRunner($service);

        $exitCode = $runner->run('queue', function (): void {
            throw new RecoverableException('temporary failure', ErrorCode::CONSUME_FAILED);
        });

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $service->published);
        $this->assertSame('retry-exchange', $service->published[0]['exchange']);
        $this->assertSame('component.retry.1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testConsumerOptionsAreMergedByRunner(): void
    {
        $service = new RunnerRecoverableRetryRabbitMqService();
        $runner = new ConsumeRunner($service);
        $handler = function (): void {
            throw new RecoverableException('temporary failure', ErrorCode::CONSUME_FAILED);
        };
        $consumer = new RuntimeConsumer('queue', $handler, [
            'managedRetry' => true,
            'retryPolicy' => [
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => 'consumer.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'consumer.dead',
            ],
        ]);

        $exitCode = $runner->run('queue', $handler, [
            'consumer' => $consumer,
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $service->published);
        $this->assertSame('consumer.retry.1', $service->published[0]['routingKey']);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testDecodeFailureUsesManagedRetryPolicy(): void
    {
        $service = new RunnerDecodeRetryRabbitMqService();
        $runner = new ConsumeRunner($service);

        $exitCode = $runner->run('queue', function (): bool {
            return true;
        }, [
            'consumeFailFast' => false,
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryExchange' => 'retry-ex',
                'retryQueues' => [
                    ['name' => 'queue.retry.1', 'ttlMs' => 5000],
                ],
                'deadQueue' => 'queue.dead',
            ],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertCount(1, $service->published);
        $this->assertSame('retry-ex', $service->published[0]['exchange']);
        $this->assertSame('queue.retry.1', $service->published[0]['routingKey']);
        $this->assertSame(1, $service->published[0]['headers']['x-retry-count']);
    }
}
