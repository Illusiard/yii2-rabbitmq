<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\amqp\AmqpConsumer;

class ConsumeController extends Controller
{
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
            $rabbit = Yii::$app->get('rabbitmq');
            $handlerInstance = Yii::createObject($handler);
            if (!is_callable($handlerInstance)) {
                throw new \InvalidArgumentException('Handler must be callable via __invoke.');
            }

            $wrappedHandler = function (string $body, array $meta) use ($handlerInstance, $memoryLimitBytes) {
                if (memory_get_usage(true) > $memoryLimitBytes) {
                    throw new \RuntimeException('Memory limit exceeded.');
                }

                return $handlerInstance($body, $meta);
            };

            $consumer = $rabbit->getConnection()->getConsumer();
            if ($consumer instanceof AmqpConsumer) {
                $consumer->setStopChecker(function () use (&$shutdownRequested) {
                    return $shutdownRequested;
                });
            }

            Yii::info('Consumer started for queue: ' . $queue, 'rabbitmq');
            $consumer->consume($queue, $wrappedHandler, $prefetch);
            Yii::info('Consumer stopped for queue: ' . $queue, 'rabbitmq');
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            Yii::error('Consumer failed: ' . $e->getMessage(), 'rabbitmq');
            return 1;
        }

        return 0;
    }
}
