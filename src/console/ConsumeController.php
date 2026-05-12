<?php

namespace illusiard\rabbitmq\console;

use InvalidArgumentException;
use JsonException;
use ReflectionException;
use Throwable;
use Yii;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use yii\base\InvalidConfigException;

class ConsumeController extends BaseRabbitMqController
{
    public ?int      $managedRetry                = null;
    public ?string   $retryPolicy                 = null;
    public ?int      $consumeFailFast             = null;
    public ?string   $fatalExceptionClasses       = null;
    public ?string   $recoverableExceptionClasses = null;
    private ?RunnerOptions $runnerOptions         = null;
    public ?string $readyLock                     = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'managedRetry',
            'retryPolicy',
            'consumeFailFast',
            'fatalExceptionClasses',
            'recoverableExceptionClasses',
            'readyLock',
        ]);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'r' => 'readyLock',
        ]);
    }

    /**
     * @param string $consumerId
     * @param int $memoryLimitMb
     * @return int
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function actionIndex(string $consumerId, int $memoryLimitMb = 256): int
    {
        $memoryLimitBytes  = $memoryLimitMb * 1024 * 1024;

        try {
            $rabbit = $this->getRabbitService();
            try {
                $registry = $rabbit->getConsumerRegistry();
            } catch (Throwable $e) {
                $message = $this->isDiscoveryUnavailable($e)
                    ? 'Discovery is disabled; enable it to run consumers by id.'
                    : $e->getMessage();
                $this->stderr($message . PHP_EOL);
                return 1;
            }

            $consumerClass = $registry->get($consumerId);
            if ($consumerClass === null) {
                $this->stderr("Consumer not found: $consumerId\n");

                return 1;
            }

            $consumerInstance = $rabbit->createConsumerDefinition($consumerClass);

            $optionsRaw = $consumerInstance->getOptions();

            $options = $this->buildConsumeOptions($optionsRaw, $memoryLimitBytes);

            $queue   = $consumerInstance->getQueue();
            $handler = $consumerInstance->getHandler();
            $options['consumer'] = $consumerInstance;

            Yii::info('Consumer started for queue: ' . $queue, 'rabbitmq');
            $runnerOptions = $this->runnerOptions ?? new RunnerOptions();
            if ($this->readyLock !== null && $this->readyLock !== '') {
                $runnerOptions->lockFilePath = $this->readyLock;
            }
            if ($runnerOptions->consumerId === null) {
                $runnerOptions->consumerId = $consumerId;
            }

            $exitCode = $rabbit->createRunner()->run($queue, $handler, $options, $runnerOptions);
            Yii::info('Consumer stopped for queue: ' . $queue, 'rabbitmq');
        } catch (FatalException $e) {
            $this->stderr($e->getMessage() . PHP_EOL);

            return 1;
        }

        return $exitCode;
    }

    /**
     * @return int
     * @throws InvalidConfigException
     */
    public function actionConsumers(): int
    {
        $rabbit = $this->getRabbitService();

        try {
            $registry = $rabbit->getConsumerRegistry();
        } catch (Throwable $e) {
            $message = $this->isDiscoveryUnavailable($e)
                ? 'Discovery is disabled; enable it to list consumers.'
                : $e->getMessage();
            $this->stderr($message . PHP_EOL);
            return 1;
        }

        $consumers = $registry->all();
        if (empty($consumers)) {
            $this->stdout("No consumers found.\n");

            return 0;
        }

        ksort($consumers);
        foreach ($consumers as $id => $fqcn) {
            $this->stdout($id . "\t" . $fqcn . PHP_EOL);
        }

        return 0;
    }

    /**
     * @param array $options
     * @param int $memoryLimitBytes
     * @return array
     * @throws JsonException
     */
    private function buildConsumeOptions(array $options, int $memoryLimitBytes): array
    {
        if ($this->consumeFailFast !== null) {
            $options['consumeFailFast'] = (bool)$this->consumeFailFast;
        }

        if ($this->fatalExceptionClasses !== null) {
            $options['fatalExceptionClasses'] = $this->parseClassList($this->fatalExceptionClasses);
        }

        if ($this->recoverableExceptionClasses !== null) {
            $options['recoverableExceptionClasses'] = $this->parseClassList($this->recoverableExceptionClasses);
        }

        if ($memoryLimitBytes > 0) {
            $middlewares                   = $options['consumeMiddlewares'] ?? $options['middlewares'] ?? [];
            $middlewares[]                 = [
                'class'            => MemoryLimitMiddleware::class,
                'memoryLimitBytes' => $memoryLimitBytes,
            ];
            $options['consumeMiddlewares'] = $middlewares;
            unset($options['middlewares']);
        }

        if ($this->managedRetry !== null) {
            $options['managedRetry'] = (bool)$this->managedRetry;
        }

        if ($this->retryPolicy !== null && $this->retryPolicy !== '') {
            $options['retryPolicy'] = $this->parseRetryPolicy($this->retryPolicy);
        }

        return $options;
    }

    private function parseClassList(string $value): array
    {
        $items = array_map('trim', explode(',', $value));

        return array_values(array_filter($items, static fn($item) => $item !== ''));
    }

    /**
     * @param string $value
     * @return array
     * @throws JsonException
     */
    private function parseRetryPolicy(string $value): array
    {
        $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new InvalidArgumentException('retryPolicy must be valid JSON: ' . json_last_error_msg());
        }
        if (!is_array($data)) {
            throw new InvalidArgumentException('retryPolicy must decode to array.');
        }

        return $data;
    }

    public function setRunnerOptions(?RunnerOptions $options): void
    {
        $this->runnerOptions = $options;
    }
}
