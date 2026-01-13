<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\consume\DefaultExceptionClassifier;
use illusiard\rabbitmq\consume\ExceptionHandlingMiddleware;
use illusiard\rabbitmq\consume\ManagedRetryPolicy;
use illusiard\rabbitmq\consume\RetryPolicyMiddleware;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;

/**
 * @group integration
 */
class RetryIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_RETRY_01_managedRetryIncrementsCount(): void
    {
        $main = $this->uniqueName('orders');
        $retry1 = $this->uniqueName('orders_retry_5s');
        $retry2 = $this->uniqueName('orders_retry_30s');
        $dead = $this->uniqueName('orders_dead');

        $this->declareQueue($main);
        $this->declareQueue($retry1);
        $this->declareQueue($retry2);
        $this->declareQueue($dead);

        $this->publishRaw('payload', '', $main);

        $options = [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryQueues' => [
                    ['name' => $retry1, 'ttlMs' => 5000],
                    ['name' => $retry2, 'ttlMs' => 30000],
                ],
                'deadQueue' => $dead,
            ],
        ];

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);

        $processed = 0;
        $shouldStop = false;
        $consumer->setStopChecker(function () use (&$shouldStop): bool {
            return $shouldStop;
        });

        $handler = function () use (&$processed, &$shouldStop): bool {
            $processed++;
            $shouldStop = true;
            return false;
        };

        $pipelineHandler = $this->buildPipelineHandler($main, $handler, $options);
        $consumer->consume($main, $pipelineHandler, 1);

        $message = $this->waitForMessage($retry1, 5);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertSame(1, $headers['x-retry-count'] ?? null);
    }

    public function testAMQP_RETRY_02_deadDecision(): void
    {
        $main = $this->uniqueName('orders');
        $dead = $this->uniqueName('orders_dead');

        $this->declareQueue($main);
        $this->declareQueue($dead);

        $this->publishRaw('payload', '', $main, [], ['x-retry-count' => 1]);

        $options = [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 1,
                'retryQueues' => [
                    ['name' => $this->uniqueName('retry_unused'), 'ttlMs' => 5000],
                ],
                'deadQueue' => $dead,
            ],
        ];

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);

        $shouldStop = false;
        $consumer->setStopChecker(function () use (&$shouldStop): bool {
            return $shouldStop;
        });

        $handler = function () use (&$shouldStop): bool {
            $shouldStop = true;
            return false;
        };

        $pipelineHandler = $this->buildPipelineHandler($main, $handler, $options);
        $consumer->consume($main, $pipelineHandler, 1);

        $message = $this->waitForMessage($dead, 5);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertSame(1, $headers['x-retry-count'] ?? null);
    }

    private function buildPipelineHandler(string $queue, callable $handler, array $options): callable
    {
        $consumerDef = new class ($queue, $handler, $options) implements ConsumerInterface {
            private string $queue;
            private $handler;
            private array $options;

            public function __construct(string $queue, $handler, array $options)
            {
                $this->queue = $queue;
                $this->handler = $handler;
                $this->options = $options;
            }

            public function getQueue(): string
            {
                return $this->queue;
            }

            public function getHandler()
            {
                return $this->handler;
            }

            public function getOptions(): array
            {
                return $this->options;
            }

            public function getMiddlewares(): array
            {
                return [];
            }
        };

        $classifier = new DefaultExceptionClassifier(true);
        $retryPolicy = new ManagedRetryPolicy($this->service, $options);
        $exceptionMiddleware = new ExceptionHandlingMiddleware($classifier);
        $retryMiddleware = new RetryPolicyMiddleware($retryPolicy);

        $core = function (ConsumeContext $context) use ($handler): ConsumeResult {
            $meta = $context->getMeta();
            $metaArray = [
                'body' => $meta->getBody(),
                'delivery_tag' => $meta->getDeliveryTag(),
                'routing_key' => $meta->getRoutingKey(),
                'exchange' => $meta->getExchange(),
                'redelivered' => $meta->isRedelivered(),
                'headers' => $meta->getHeaders(),
                'properties' => $meta->getProperties(),
            ];

            $result = $handler((string)$meta->getBody(), $metaArray);
            return ConsumeResult::normalizeHandlerResult($result);
        };

        $pipeline = function (ConsumeContext $context) use ($exceptionMiddleware, $retryMiddleware, $core): ConsumeResult {
            $next = function (ConsumeContext $context) use ($retryMiddleware, $core): ConsumeResult {
                return $retryMiddleware->process($context, $core);
            };

            return $exceptionMiddleware->process($context, $next);
        };

        return function (string $body, array $meta) use ($pipeline, $consumerDef): ConsumeResult {
            $headers = isset($meta['headers']) && is_array($meta['headers']) ? $meta['headers'] : [];
            $properties = isset($meta['properties']) && is_array($meta['properties']) ? $meta['properties'] : [];
            $deliveryTag = isset($meta['delivery_tag']) && is_int($meta['delivery_tag']) ? $meta['delivery_tag'] : null;
            $routingKey = isset($meta['routing_key']) && is_string($meta['routing_key']) ? $meta['routing_key'] : null;
            $exchange = isset($meta['exchange']) && is_string($meta['exchange']) ? $meta['exchange'] : null;
            $redelivered = isset($meta['redelivered']) ? (bool)$meta['redelivered'] : false;

            $messageMeta = new MessageMeta($headers, $properties, $body, $deliveryTag, $routingKey, $exchange, $redelivered);
            $envelope = $this->service->decodeEnvelope($body, $meta);
            $context = new ConsumeContext($envelope, $messageMeta, $this->service, $consumerDef);

            return $pipeline($context);
        };
    }
}
