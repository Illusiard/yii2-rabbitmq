<?php

namespace illusiard\rabbitmq\orchestration;

use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\helpers\FileHelper;
use Throwable;
use Yii;

class ConsumeRunner
{
    private RabbitMqService $service;
    private bool $stopRequested = false;

    public function __construct(RabbitMqService $service)
    {
        $this->service = $service;
    }

    public function run(string $queue, $handler, array $options = [], ?RunnerOptions $runnerOptions = null): int
    {
        $runnerOptions = $runnerOptions ?? new RunnerOptions();
        if ($runnerOptions->consumerId === null) {
            $runnerOptions->consumerId = $queue;
        }

        $lockFilePath = $this->resolveLockFilePath($queue, $runnerOptions);
        $this->stopRequested = false;

        $this->installSignalHandlers();

        try {
            if ($lockFilePath !== null && $runnerOptions->createLockOnStart) {
                FileHelper::atomicWrite($lockFilePath, '');
            }

            $consumer = $this->service->getConnection()->getConsumer();
            if ($consumer instanceof AmqpConsumer) {
                $consumer->setStopChecker(function (): bool {
                    return $this->stopRequested;
                });
            }

            $this->service->consume($queue, $handler, $options);

            return 0;
        } catch (Throwable $e) {
            Yii::error('Consume runner failed: ' . get_class($e) . ': ' . $e->getMessage(), 'rabbitmq');
            return 1;
        } finally {
            if ($lockFilePath !== null && $runnerOptions->removeLockOnStop) {
                FileHelper::removeFileQuietly($lockFilePath);
            }
        }
    }

    private function installSignalHandlers(): void
    {
        if (!function_exists('pcntl_async_signals') || !function_exists('pcntl_signal')) {
            Yii::warning('pcntl extension is not available; graceful shutdown disabled', 'rabbitmq');
            return;
        }

        pcntl_async_signals(true);
        $handler = function (int $signal): void {
            $this->stopRequested = true;
            if ($signal === SIGTERM) {
                Yii::warning('Shutdown requested (SIGTERM)', 'rabbitmq');
            } elseif ($signal === SIGINT) {
                Yii::warning('Shutdown requested (SIGINT)', 'rabbitmq');
            } else {
                Yii::warning('Shutdown requested (signal ' . $signal . ')', 'rabbitmq');
            }
        };

        if (defined('SIGTERM')) {
            pcntl_signal(SIGTERM, $handler);
        }

        if (defined('SIGINT')) {
            pcntl_signal(SIGINT, $handler);
        }
    }

    private function resolveLockFilePath(string $queue, RunnerOptions $options): ?string
    {
        if ($options->lockFilePath !== null && $options->lockFilePath !== '') {
            return $options->lockFilePath;
        }

        if ($options->lockFileDir === null || $options->lockFileDir === '') {
            return null;
        }

        $base = $options->consumerId ?: $queue;
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        if ($safe === null || $safe === '') {
            $safe = 'consumer';
        }

        return rtrim($options->lockFileDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.lock';
    }
}
