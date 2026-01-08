<?php

namespace illusiard\rabbitmq\tests\integration;

use PhpAmqpLib\Exception\AMQPTimeoutException;

/**
 * @group integration
 */
class QosIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_QOS_01_prefetchBehavior(): void
    {
        $queue = $this->uniqueName('qos_q');
        $this->declareQueue($queue);

        $this->publishRaw('one', '', $queue);
        $this->publishRaw('two', '', $queue);

        $channel = $this->getChannel();
        $channel->basic_qos(null, 1, null);

        $received = 0;
        $firstTag = null;
        $secondReceived = false;

        $consumerTag = $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function ($message) use (&$received, &$firstTag, &$secondReceived) {
                $received++;
                if ($received === 1) {
                    $firstTag = $message->getDeliveryTag();
                    return;
                }
                $secondReceived = true;
                $message->getChannel()->basic_ack($message->getDeliveryTag());
            }
        );

        $deadline = microtime(true) + 1.5;
        while (microtime(true) < $deadline) {
            try {
                $channel->wait(null, false, 0.2);
            } catch (AMQPTimeoutException $e) {
                // просто нет данных за 0.2 сек — это не ошибка
            }
        }

        $this->assertFalse($secondReceived, 'Second message should not be delivered before first ACK.');

        if ($firstTag !== null) {
            $channel->basic_ack($firstTag);
        }

        $deadline = microtime(true) + 2;
        while (microtime(true) < $deadline && !$secondReceived) {
            try {
                $channel->wait(null, false, 0.2);
            } catch (AMQPTimeoutException $e) {
                // просто нет данных за 0.2 сек — это не ошибка
            }
        }

        $this->assertTrue($secondReceived, 'Second message should be delivered after ACK.');

        if ($consumerTag) {
            $channel->basic_cancel($consumerTag);
        }

        if ($channel->is_open()) {
            $channel->close();
        }
    }
}
