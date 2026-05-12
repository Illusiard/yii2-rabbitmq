<?php

namespace illusiard\rabbitmq\orchestration;

use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\consume\DefaultExceptionClassifier;
use illusiard\rabbitmq\consume\ExceptionHandlingMiddleware;
use illusiard\rabbitmq\consume\LegacyConsumeMiddlewareAdapter;
use illusiard\rabbitmq\consume\ManagedRetryPolicy;
use illusiard\rabbitmq\consume\RetryPolicyMiddleware;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMetaFactory;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\consumer\RuntimeConsumer;
use illusiard\rabbitmq\definitions\handler\HandlerInterface;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use illusiard\rabbitmq\helpers\FileHelper;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\middleware\ConsumeMiddlewareInterface;
use illusiard\rabbitmq\profile\OptionsMerger;
use InvalidArgumentException;
use JsonException;
use ReflectionException;
use Throwable;
use Yii;
use yii\base\InvalidConfigException;

class ConsumeRunner
{
    private RabbitMqService $service;
    private bool $stopRequested = false;

    public function __construct(RabbitMqService $service)
    {
        $this->service = $service;
    }

    /**
     * @param string $queue
     * @param $handler
     * @param array $options
     * @param ?RunnerOptions $runnerOptions
     * @return int
     * @throws InvalidConfigException
     */
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

            $prefetch = isset($options['prefetch']) ? (int)$options['prefetch'] : 1;
            $pipelineHandler = $this->buildPipelineHandler($queue, $handler, $options);

            $consumer->consume($queue, $pipelineHandler, $prefetch);

            return 0;
        } catch (InvalidConfigException $e) {
            throw $e;
        } catch (Throwable $e) {
            Yii::error('Consume runner failed: exception=' . get_class($e), 'rabbitmq');
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

        $lockFileDir = $options->lockFileDir;
        if ($lockFileDir === null || $lockFileDir === '') {
            $lockFileDir = $this->defaultLockFileDir();
        }

        if ($lockFileDir === null || $lockFileDir === '') {
            return null;
        }

        $base = $options->consumerId ?: $queue;
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '_', $base);
        if ($safe === null || $safe === '') {
            $safe = 'consumer';
        }

        return rtrim($lockFileDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $safe . '.lock';
    }

    private function defaultLockFileDir(): ?string
    {
        $runtime = Yii::getAlias('@runtime/rabbitmq', false);
        if (is_string($runtime) && $runtime !== '') {
            return $runtime;
        }

        $cwd = getcwd();
        if ($cwd === false || $cwd === '') {
            return null;
        }

        return $cwd . DIRECTORY_SEPARATOR . 'runtime' . DIRECTORY_SEPARATOR . 'rabbitmq';
    }

    /**
     * @param string $queue
     * @param $handler
     * @param array $options
     * @return callable
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    private function buildPipelineHandler(string $queue, $handler, array $options): callable
    {
        $consumer = $this->resolveConsumer($queue, $handler, $options);
        $options = $this->service->mergeConsumeOptions(OptionsMerger::merge($consumer->getOptions(), $options));
        $resolvedHandler = $this->resolveHandler($handler);
        $handlerClass = $this->resolveHandlerClass($resolvedHandler, $handler);
        $userMiddlewares = $this->resolveMiddlewares($consumer, $options, $handlerClass);

        $classifier = $this->buildExceptionClassifier($options);
        $retryPolicy = new ManagedRetryPolicy($this->service, $options);
        $retryPolicy->validate();

        $retryMiddleware = new RetryPolicyMiddleware($retryPolicy);
        $pipeline = array_merge(
            [
                new ExceptionHandlingMiddleware(
                    $classifier,
                    static fn(ConsumeResult $result, ConsumeContext $context): ConsumeResult => $retryMiddleware->process(
                        $context,
                        static fn(): ConsumeResult => $result
                    )
                ),
                $retryMiddleware,
            ],
            $userMiddlewares,
        );

        $core = static function (ConsumeContext $context) use ($resolvedHandler) {
            if ($resolvedHandler instanceof HandlerInterface) {
                $result = $resolvedHandler->handle($context->getEnvelope());
                return ConsumeResult::normalizeHandlerResult($result);
            }

            $meta = MessageMetaFactory::toTransportMeta($context->getMeta());
            $result = $resolvedHandler($context->getMeta()->getBody(), $meta);
            return ConsumeResult::normalizeHandlerResult($result);
        };

        $runner = array_reduce(
            array_reverse($pipeline),
            static fn($next, $middleware) => static fn(ConsumeContext $context) => $middleware->process($context, $next),
            $core
        );

        return function (string $body, array $meta) use ($runner, $consumer, $classifier, $retryMiddleware): ConsumeResult {
            try {
                $context = $this->buildContext($body, $meta, $consumer);
            } catch (Throwable $e) {
                $context = $this->buildFallbackContext($body, $meta, $consumer);
                $result = $classifier->classify($e, $context);

                return $this->finalizeResult($retryMiddleware->process($context, static fn(): ConsumeResult => $result));
            }

            return $this->finalizeResult($runner($context));
        };
    }

    private function resolveConsumer(string $queue, $handler, array $options): ConsumerInterface
    {
        if (isset($options['consumer']) && $options['consumer'] instanceof ConsumerInterface) {
            return $options['consumer'];
        }

        return new RuntimeConsumer($queue, $handler, $options);
    }

    /**
     * @param $handler
     * @return callable|HandlerInterface|object|string
     * @throws InvalidConfigException
     */
    private function resolveHandler($handler)
    {
        if (is_string($handler)) {
            $handler = Yii::createObject($handler);

            if (!$handler instanceof HandlerInterface) {
                throw new InvalidArgumentException(
                    'String handler must resolve to a class implementing definitions HandlerInterface.'
                );
            }
        }

        if ($handler instanceof HandlerInterface) {
            return $handler;
        }

        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Handler must be a callable or implement definitions HandlerInterface.');
        }

        return $handler;
    }

    private function resolveHandlerClass($resolvedHandler, $originalHandler): string
    {
        if (is_object($resolvedHandler)) {
            return get_class($resolvedHandler);
        }

        if (is_string($originalHandler)) {
            return $originalHandler;
        }

        return '';
    }

    /**
     * @param ConsumerInterface $consumer
     * @param array $options
     * @param string $handlerClass
     * @return array
     * @throws InvalidConfigException
     * @throws JsonException
     */
    private function resolveMiddlewares(ConsumerInterface $consumer, array $options, string $handlerClass): array
    {
        $middlewares = [];
        $seen = [];
        $registry = null;

        foreach ($consumer->getMiddlewares() as $middlewareId) {
            if (!is_string($middlewareId)) {
                throw new InvalidArgumentException('Consumer middleware id must be a string.');
            }

            $class = $this->resolveMiddlewareClass($middlewareId, $registry);
            $this->appendMiddleware($middlewares, $seen, $class, $consumer, $handlerClass);
        }

        $optionMiddlewares = $options['consumeMiddlewares'] ?? $options['middlewares'] ?? [];
        if (is_array($optionMiddlewares)) {
            foreach ($optionMiddlewares as $middleware) {
                if (is_string($middleware)) {
                    $class = $this->resolveMiddlewareClass($middleware, $registry);
                    $this->appendMiddleware($middlewares, $seen, $class, $consumer, $handlerClass);
                    continue;
                }

                if (is_array($middleware) && isset($middleware['class']) && is_string($middleware['class'])) {
                    $this->appendMiddleware(
                        $middlewares,
                        $seen,
                        $middleware['class'],
                        $consumer,
                        $handlerClass,
                        $middleware
                    );
                    continue;
                }

                if (is_array($middleware) && isset($middleware['id']) && is_string($middleware['id'])) {
                    $middleware['class'] = $this->resolveMiddlewareClass($middleware['id'], $registry);
                    unset($middleware['id']);
                    $this->appendMiddleware(
                        $middlewares,
                        $seen,
                        $middleware['class'],
                        $consumer,
                        $handlerClass,
                        $middleware
                    );
                    continue;
                }

                if (is_object($middleware)) {
                    $this->appendMiddlewareInstance(
                        $middlewares,
                        $seen,
                        $middleware,
                        $consumer,
                        $handlerClass
                    );
                    continue;
                }

                throw new InvalidArgumentException('Consume middleware must be a class name or config with class/id.');
            }
        }

        return $middlewares;
    }

    private function resolveMiddlewareClass(string $middleware, $registry): string
    {
        if (class_exists($middleware)) {
            return $middleware;
        }

        if ($registry !== null) {
            $resolved = $registry->get($middleware);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $registry = $this->service->getMiddlewareRegistry();
        $resolved = $registry->get($middleware);
        if ($resolved !== null) {
            return $resolved;
        }

        throw new InvalidArgumentException("Middleware not found: $middleware");
    }

    /**
     * @param string $class
     * @param ConsumerInterface $consumer
     * @param string $handlerClass
     * @param array $config
     * @return MiddlewareInterface
     * @throws InvalidConfigException
     */
    private function instantiateMiddleware(string $class, ConsumerInterface $consumer, string $handlerClass, array $config = []): MiddlewareInterface
    {
        $config = $config ?: ['class' => $class];
        $instance = Yii::createObject($config);

        if ($instance instanceof MiddlewareInterface) {
            return $instance;
        }

        if ($instance instanceof ConsumeMiddlewareInterface) {
            return new LegacyConsumeMiddlewareAdapter($instance, $this->service, $consumer, $handlerClass);
        }

        throw new InvalidArgumentException('Middleware must implement definitions MiddlewareInterface.');
    }

    /**
     * @param array $middlewares
     * @param array $seen
     * @param string $class
     * @param ConsumerInterface $consumer
     * @param string $handlerClass
     * @param array $config
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    private function appendMiddleware(
        array &$middlewares,
        array &$seen,
        string $class,
        ConsumerInterface $consumer,
        string $handlerClass,
        array $config = []
    ): void {
        $config = $config ?: ['class' => $class];
        $key = $this->middlewareDefinitionKey($class, $config);
        if (isset($seen[$key])) {
            return;
        }

        $instance = $this->instantiateMiddleware($class, $consumer, $handlerClass, $config);
        $instanceKey = 'object:' . spl_object_id($instance);
        if (isset($seen[$instanceKey])) {
            return;
        }

        $middlewares[] = $instance;
        $seen[$key] = true;
        $seen[$instanceKey] = true;
    }

    private function appendMiddlewareInstance(
        array &$middlewares,
        array &$seen,
        object $instance,
        ConsumerInterface $consumer,
        string $handlerClass
    ): void {
        $instanceKey = 'object:' . spl_object_id($instance);
        if (isset($seen[$instanceKey])) {
            return;
        }

        if ($instance instanceof MiddlewareInterface) {
            $middleware = $instance;
        } elseif ($instance instanceof ConsumeMiddlewareInterface) {
            $middleware = new LegacyConsumeMiddlewareAdapter($instance, $this->service, $consumer, $handlerClass);
        } else {
            throw new InvalidArgumentException('Middleware must implement definitions MiddlewareInterface.');
        }

        $middlewares[] = $middleware;
        $seen[$instanceKey] = true;
    }

    /**
     * @param string $class
     * @param array $config
     * @return string
     * @throws JsonException
     */
    private function middlewareDefinitionKey(string $class, array $config): string
    {
        $normalized = $this->normalizeMiddlewareConfig($config);

        return 'config:' . $class . ':' . sha1(json_encode($normalized, JSON_THROW_ON_ERROR) ?: serialize($normalized));
    }

    private function normalizeMiddlewareConfig($value)
    {
        if (is_array($value)) {
            $normalized = array_map(fn($item) => $this->normalizeMiddlewareConfig($item), $value);
            if (!array_is_list($normalized)) {
                ksort($normalized);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return [
                '__object' => get_class($value),
                'id' => spl_object_id($value),
            ];
        }

        if (is_resource($value)) {
            return [
                '__resource' => get_resource_type($value),
            ];
        }

        return $value;
    }

    private function buildExceptionClassifier(array $options): DefaultExceptionClassifier
    {
        $consumeFailFast = !isset($options['consumeFailFast']) || $options['consumeFailFast'];

        $fatal = $options['fatalExceptions'] ?? $options['fatalExceptionClasses'] ?? [];
        $recoverable = $options['recoverableExceptions'] ?? $options['recoverableExceptionClasses'] ?? [];

        $fatal = is_array($fatal) ? $fatal : [];
        $recoverable = is_array($recoverable) ? $recoverable : [];

        return new DefaultExceptionClassifier($consumeFailFast, $fatal, $recoverable);
    }

    /**
     * @param string $body
     * @param array $meta
     * @param ConsumerInterface $consumer
     * @return ConsumeContext
     * @throws InvalidConfigException
     */
    private function buildContext(string $body, array $meta, ConsumerInterface $consumer): ConsumeContext
    {
        return $this->createContext($this->service->decodeEnvelope($body, $meta), $body, $meta, $consumer);
    }

    /**
     * @param string $body
     * @param array $meta
     * @param ConsumerInterface $consumer
     * @return ConsumeContext
     */
    private function buildFallbackContext(string $body, array $meta, ConsumerInterface $consumer): ConsumeContext
    {
        return $this->createContext(new Envelope($body), $body, $meta, $consumer);
    }

    private function createContext(Envelope $envelope, string $body, array $meta, ConsumerInterface $consumer): ConsumeContext
    {
        return new ConsumeContext(
            $envelope,
            MessageMetaFactory::fromTransportMeta($body, $meta),
            $this->service,
            $consumer,
            $this->stopRequested
        );
    }

    private function finalizeResult($result): ConsumeResult
    {
        $normalized = ConsumeResult::normalizeHandlerResult($result);
        if ($normalized->getAction() === ConsumeResult::ACTION_STOP) {
            $this->stopRequested = true;
        }

        return $normalized;
    }

}
