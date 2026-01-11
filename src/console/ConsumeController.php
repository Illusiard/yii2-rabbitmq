<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;

class ConsumeController extends Controller
{
    public ?int $managedRetry = null;
    public ?string $retryPolicy = null;
    public ?int $consumeFailFast = null;
    public ?string $fatalExceptionClasses = null;
    public ?string $recoverableExceptionClasses = null;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'managedRetry',
            'retryPolicy',
            'consumeFailFast',
            'fatalExceptionClasses',
            'recoverableExceptionClasses',
        ]);
    }

    public function actionIndex(string $queue, string $handler, int $prefetch = 1, int $memoryLimitMb = 256): int
    {
        $memoryLimitBytes = $memoryLimitMb * 1024 * 1024;
        $shutdownRequested = false;

        if (function_exists('pcntl_signal')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, function () use (&$shutdownRequested) {
                $shutdownRequested = true;
                Yii::warning('Shutdown requested (SIGTERM)', 'rabbitmq');
            });
            pcntl_signal(SIGINT, function () use (&$shutdownRequested) {
                $shutdownRequested = true;
                Yii::warning('Shutdown requested (SIGINT)', 'rabbitmq');
            });
        } else {
            Yii::warning('pcntl extension is not available; graceful shutdown disabled', 'rabbitmq');
        }

        try {
            $rabbit = Yii::$app->rabbitmq;
            $this->applyConsumeOptions($rabbit, $memoryLimitBytes);
            $this->applyRetryOptions($rabbit);

            $consumer = $rabbit->getConnection()->getConsumer();
            if ($consumer instanceof AmqpConsumer) {
                $consumer->setStopChecker(function () use (&$shutdownRequested) {
                    return $shutdownRequested;
                });
            }

            Yii::info('Consumer started for queue: ' . $queue, 'rabbitmq');
            $rabbit->consume($queue, $handler, $prefetch);
            Yii::info('Consumer stopped for queue: ' . $queue, 'rabbitmq');
        } catch (FatalException $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }

        return 0;
    }

    private function applyConsumeOptions($rabbit, int $memoryLimitBytes): void
    {
        if ($this->consumeFailFast !== null) {
            $rabbit->consumeFailFast = (bool)$this->consumeFailFast;
        }

        if ($this->fatalExceptionClasses !== null) {
            $rabbit->fatalExceptionClasses = $this->parseClassList($this->fatalExceptionClasses);
        }

        if ($this->recoverableExceptionClasses !== null) {
            $rabbit->recoverableExceptionClasses = $this->parseClassList($this->recoverableExceptionClasses);
        }

        if ($memoryLimitBytes > 0) {
            $middlewares = $rabbit->consumeMiddlewares;
            $middlewares[] = [
                'class' => MemoryLimitMiddleware::class,
                'memoryLimitBytes' => $memoryLimitBytes,
            ];
            $rabbit->consumeMiddlewares = $middlewares;
        }
    }

    private function applyRetryOptions($rabbit): void
    {
        if ($this->managedRetry !== null) {
            $rabbit->managedRetry = (bool)$this->managedRetry;
        }

        if ($this->retryPolicy !== null && $this->retryPolicy !== '') {
            $rabbit->retryPolicy = $this->parseRetryPolicy($this->retryPolicy);
        }
    }

    private function parseClassList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));
        return array_values(array_filter($items, function ($item) {
            return $item !== '';
        }));
    }

    private function parseRetryPolicy(string $value): array
    {
        $data = json_decode($value, true);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('retryPolicy must be valid JSON: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new \InvalidArgumentException('retryPolicy must decode to array.');
        }

        return $data;
    }
}
