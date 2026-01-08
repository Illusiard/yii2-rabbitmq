<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\AmqpConsumer;

/**
 * @group integration
 */
class ReconnectIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_RECONNECT_01_publisherReconnect(): void
    {
        if (!$this->envFlagEnabled('KILL_CONNECTION')) {
            $this->markTestSkipped('Set KILL_CONNECTION=1 to run reconnect tests.');
        }

        $exchange = $this->uniqueName('reconnect_ex');
        $queue = $this->uniqueName('reconnect_q');
        $routingKey = 'rk';

        $this->declareExchange($exchange);
        $this->declareQueue($queue);
        $this->bindQueue($queue, $exchange, $routingKey);

        $service = $this->createService();
        $this->setService($service);

        $service->publish('one', $exchange, $routingKey);

        $service->getConnection()->close();

        $service->publish('two', $exchange, $routingKey);

        $this->assertTrue($this->waitForQueueCount($queue, 2));
    }

    public function testAMQP_RECONNECT_02_consumerReconnect(): void
    {
        if (!$this->envFlagEnabled('KILL_CONNECTION')) {
            $this->markTestSkipped('Set KILL_CONNECTION=1 to run reconnect tests.');
        }

        $queue = $this->uniqueName('reconnect_consume_q');
        $this->declareQueue($queue);

        $this->publishRaw('one', '', $queue);
        $this->publishRaw('two', '', $queue);

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);

        $processed = 0;
        $shouldStop = false;

        $consumer->setStopChecker(function () use (&$shouldStop): bool {
            return $shouldStop;
        });

        $handler = function (string $body, array $meta) use (&$processed, &$shouldStop, $connection): bool {
            $processed++;
            if ($processed === 1) {
                $connection->close();
            }
            if ($processed >= 2) {
                $shouldStop = true;
            }
            return true;
        };

        $consumer->consume($queue, $handler, 1);

        $this->assertGreaterThanOrEqual(2, $processed);
    }
}
