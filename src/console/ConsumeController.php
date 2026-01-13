<?php

namespace illusiard\rabbitmq\console;

use Closure;
use illusiard\rabbitmq\components\RabbitMqService;
use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\handler\HandlerInterface;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\exceptions\FatalException;
use illusiard\rabbitmq\middleware\MemoryLimitMiddleware;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use InvalidArgumentException;

class ConsumeController extends Controller
{
    public string $component = 'rabbitmq';
    public ?int      $managedRetry                = null;
    public ?string   $retryPolicy                 = null;
    public ?int      $consumeFailFast             = null;
    public ?string   $fatalExceptionClasses       = null;
    public ?string   $recoverableExceptionClasses = null;
    private ?Closure $onStart                     = null;
    private ?RunnerOptions $runnerOptions         = null;
    public ?string $readyLock                     = null;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), [
            'component',
            'managedRetry',
            'retryPolicy',
            'consumeFailFast',
            'fatalExceptionClasses',
            'recoverableExceptionClasses',
            'readyLock',
        ]);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'component',
            'r' => 'readyLock',
        ]);
    }

    public function actionIndex(string $consumerId, int $memoryLimitMb = 256): int
    {
        $memoryLimitBytes  = $memoryLimitMb * 1024 * 1024;

        try {
            $rabbit = $this->getRabbitService();
            try {
                $registry = $rabbit->getConsumerRegistry();
            } catch (\Throwable $e) {
                $this->stderr("Discovery is disabled; enable it to run consumers by id.\n");
                return 1;
            }

            $consumerClass = $registry->get($consumerId);
            if ($consumerClass === null) {
                $this->stderr("Consumer not found: {$consumerId}\n");

                return 1;
            }

            $consumerInstance = Yii::createObject($consumerClass);
            if (!$consumerInstance instanceof ConsumerInterface) {
                $this->stderr("Consumer class '{$consumerClass}' must implement definitions ConsumerInterface.\n");

                return 1;
            }

            $optionsRaw = $consumerInstance->getOptions();
            if (!is_array($optionsRaw)) {
                $this->stderr("Consumer options must be an array.\n");

                return 1;
            }

            $options = $this->buildConsumeOptions($optionsRaw, $memoryLimitBytes);

            $queue   = $consumerInstance->getQueue();
            $handler = $this->normalizeHandler($rabbit, $consumerInstance->getHandler());
            $options = $this->applyConsumerMiddlewares($rabbit, $options, $consumerInstance->getMiddlewares());

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

        return $exitCode ?? 0;
    }

    public function actionConsumers(): int
    {
        $rabbit = $this->getRabbitService();

        try {
            $registry = $rabbit->getConsumerRegistry();
        } catch (\Throwable $e) {
            $this->stderr("Discovery is disabled; enable it to list consumers.\n");
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

    public function setOnStart(?Closure $param): void
    {
        $this->onStart = $param;
    }

    public function setRunnerOptions(?RunnerOptions $options): void
    {
        $this->runnerOptions = $options;
    }

    private function getRabbitService(): RabbitMqService
    {
        $service = Yii::$app->get($this->component);
        if (!$service instanceof RabbitMqService) {
            throw new InvalidArgumentException("Component '{$this->component}' must be an instance of RabbitMqService.");
        }

        return $service;
    }

    private function normalizeHandler(RabbitMqService $service, $handler)
    {
        if (is_string($handler) && is_subclass_of($handler, HandlerInterface::class)) {
            $handler = Yii::createObject($handler);
        }

        if ($handler instanceof HandlerInterface) {
            return function (string $body, array $meta) use ($service, $handler): bool {
                $envelope = $service->decodeEnvelope($body, $meta);
                $result = $handler->handle($envelope);
                $normalized = ConsumeResult::normalizeHandlerResult($result);
                return $normalized->getAction() === ConsumeResult::ACTION_ACK;
            };
        }

        return $handler;
    }

    private function applyConsumerMiddlewares(RabbitMqService $service, array $options, array $middlewares): array
    {
        if (empty($middlewares)) {
            return $options;
        }

        $registry = null;
        try {
            $registry = $service->getMiddlewareRegistry();
        } catch (\Throwable $e) {
        }

        $resolved = [];
        foreach ($middlewares as $middleware) {
            if (is_string($middleware)) {
                if (class_exists($middleware)) {
                    $resolved[] = $middleware;
                    continue;
                }

                if ($registry !== null) {
                    $fqcn = $registry->get($middleware);
                    if ($fqcn === null) {
                        throw new InvalidArgumentException("Middleware not found: {$middleware}");
                    }
                    $resolved[] = $fqcn;
                    continue;
                }

                throw new InvalidArgumentException("Middleware not found: {$middleware}");
            }

            if (is_array($middleware)) {
                if (isset($middleware['class']) && is_string($middleware['class'])) {
                    $resolved[] = $middleware;
                    continue;
                }

                if (isset($middleware['id']) && is_string($middleware['id']) && $registry !== null) {
                    $fqcn = $registry->get($middleware['id']);
                    if ($fqcn === null) {
                        throw new InvalidArgumentException("Middleware not found: {$middleware['id']}");
                    }
                    $middleware['class'] = $fqcn;
                    unset($middleware['id']);
                    $resolved[] = $middleware;
                    continue;
                }
            }

            $resolved[] = $middleware;
        }

        $existing = $options['consumeMiddlewares'] ?? $options['middlewares'] ?? [];
        if (!is_array($existing)) {
            $existing = [];
        }

        $options['consumeMiddlewares'] = array_merge($existing, $resolved);
        unset($options['middlewares']);

        return $options;
    }
}
