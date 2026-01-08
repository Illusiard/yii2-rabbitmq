<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\AmqpConsumer;

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

        $policy = [
            'maxAttempts' => 3,
            'retryQueues' => [
                ['name' => $retry1, 'ttlMs' => 5000],
                ['name' => $retry2, 'ttlMs' => 30000],
            ],
            'deadQueue' => $dead,
        ];

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);
        $consumer->setManagedRetry(true, $policy, $this->service->getPublisher());

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

        $consumer->consume($main, $handler, 1);

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

        $policy = [
            'maxAttempts' => 1,
            'retryQueues' => [
                ['name' => $this->uniqueName('retry_unused'), 'ttlMs' => 5000],
            ],
            'deadQueue' => $dead,
        ];

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);
        $consumer->setManagedRetry(true, $policy, $this->service->getPublisher());

        $shouldStop = false;
        $consumer->setStopChecker(function () use (&$shouldStop): bool {
            return $shouldStop;
        });

        $handler = function () use (&$shouldStop): bool {
            $shouldStop = true;
            return false;
        };

        $consumer->consume($main, $handler, 1);

        $message = $this->waitForMessage($dead, 5);
        $this->assertNotNull($message);

        $properties = $message->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertSame(1, $headers['x-retry-count'] ?? null);
    }
}
