<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\tests\fixtures\NonHandlerInvokable;
use illusiard\rabbitmq\tests\fixtures\RunnerDecodeRetryRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerLockRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerMiddlewareRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\RunnerRecordingRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\TestRunnerUserMiddleware;
use Yii;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use PHPUnit\Framework\TestCase;

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

    public function testStringHandlerMustImplementHandlerInterface(): void
    {
        $service = new RunnerRecordingRabbitMqService();

        $runner = new ConsumeRunner($service);
        $exitCode = $runner->run('queue', NonHandlerInvokable::class);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($service->consumeCalled);
    }

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
            'user-after-reject',
        ], TestRunnerUserMiddleware::$actions);
    }

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
