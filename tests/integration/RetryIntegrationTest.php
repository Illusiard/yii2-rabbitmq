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
use illusiard\rabbitmq\definitions\consumer\RuntimeConsumer;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * @group integration
 */
class RetryIntegrationTest extends IntegrationTestCase
{
    /**
     * @return void
     * @throws Throwable
     */
    public function testAMQP_RETRY_01_managedRetryIncrementsCount(): void
    {
        $main = $this->uniqueName('orders');
        $retry1 = $this->uniqueName('orders_retry_5s');
        $retry2 = $this->uniqueName('orders_retry_30s');
        $dead = $this->uniqueName('orders_dead');
        $retryExchange = $this->uniqueName('retry_ex');

        $this->declareExchange($retryExchange);
        $this->declareQueue($main);
        $this->declareQueue($retry1);
        $this->declareQueue($retry2);
        $this->declareQueue($dead);
        $this->bindQueue($retry1, $retryExchange, $retry1);
        $this->bindQueue($retry2, $retryExchange, $retry2);
        $this->service->topology = [
            'options' => [
                'retryExchange' => $retryExchange,
            ],
        ];

        $this->publishRaw('"payload"', '', $main);

        $options = [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 3,
                'retryExchange' => $this->uniqueName('wrong_retry_ex'),
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
        $consumer->consume($main, $pipelineHandler);

        $message = $this->waitForMessage($retry1);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertSame(1, $headers['x-retry-count'] ?? null);
        $this->assertTrue($this->waitForQueueCount($retry2, 0));
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testAMQP_RETRY_02_deadDecision(): void
    {
        $main = $this->uniqueName('orders');
        $retry = $this->uniqueName('orders_retry_unused');
        $dead = $this->uniqueName('orders_dead');
        $retryExchange = $this->uniqueName('retry_ex');

        $this->declareExchange($retryExchange);
        $this->declareQueue($main);
        $this->declareQueue($retry);
        $this->declareQueue($dead);
        $this->bindQueue($retry, $retryExchange, $retry);
        $this->service->topology = [
            'options' => [
                'retryExchange' => $retryExchange,
            ],
        ];

        $this->publishRaw('"payload"', '', $main, [], ['x-retry-count' => 1]);

        $options = [
            'managedRetry' => [
                'enabled' => true,
                'maxAttempts' => 1,
                'retryExchange' => $this->uniqueName('wrong_retry_ex'),
                'retryQueues' => [
                    ['name' => $retry, 'ttlMs' => 5000],
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
        $consumer->consume($main, $pipelineHandler);

        $message = $this->waitForMessage($dead);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertSame(1, $headers['x-retry-count'] ?? null);
        $this->assertTrue($this->waitForQueueCount($retry, 0));
    }

    /**
     * @param string $queue
     * @param callable $handler
     * @param array $options
     * @return callable
     * @throws InvalidConfigException
     */
    private function buildPipelineHandler(string $queue, callable $handler, array $options): callable
    {
        $consumerDef = new RuntimeConsumer($queue, $handler, $options);

        $classifier = new DefaultExceptionClassifier(true);
        $retryPolicy = new ManagedRetryPolicy($this->service, $options);
        $retryPolicy->validate();
        $retryMiddleware = new RetryPolicyMiddleware($retryPolicy);
        $exceptionMiddleware = new ExceptionHandlingMiddleware(
            $classifier,
            static fn(ConsumeResult $result, ConsumeContext $context): ConsumeResult => $retryMiddleware->process(
                $context,
                static fn(): ConsumeResult => $result
            )
        );

        $core = static function (ConsumeContext $context) use ($handler): ConsumeResult {
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

        $pipeline = static function (ConsumeContext $context) use ($exceptionMiddleware, $retryMiddleware, $core): ConsumeResult {
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
            $redelivered = isset($meta['redelivered']) && $meta['redelivered'];

            $messageMeta = new MessageMeta($headers, $properties, $body, $deliveryTag, $routingKey, $exchange, $redelivered);
            $envelope = $this->service->decodeEnvelope($body, $meta);
            $context = new ConsumeContext($envelope, $messageMeta, $this->service, $consumerDef);

            return $pipeline($context);
        };
    }
}
