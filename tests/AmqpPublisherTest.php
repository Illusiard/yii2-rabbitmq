<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\tests\fixtures\TestAmqpConnection;
use illusiard\rabbitmq\tests\fixtures\TestAmqpPublisher;

class AmqpPublisherTest extends TestCase
{
    public function testPublishDoesNotWaitWhenConfirmDisabled(): void
    {
        $channel = $this->createChannelMock([
            'basic_publish',
            'set_return_listener',
        ]);

        $connection = $this->createConnectionMock($channel);

        $publisher = new TestAmqpPublisher(
            $connection,
            [
                'confirm' => false,
                'mandatory' => true,
                'publishTimeout' => 1,
            ]
        );

        $publisher->publish('body', 'ex', 'rk');

        $this->assertFalse($publisher->waitCalled);
    }

    public function testPublishWaitsWhenConfirmEnabled(): void
    {
        $channel = $this->createChannelMock([
            'basic_publish',
            'confirm_select',
            'set_ack_handler',
            'set_nack_handler',
        ]);

        $connection = $this->createConnectionMock($channel);

        $publisher = new TestAmqpPublisher(
            $connection,
            [
                'confirm' => true,
                'mandatory' => false,
                'publishTimeout' => 1,
            ]
        );

        $publisher->publish('body', 'ex', 'rk');

        $this->assertTrue($publisher->waitCalled);
    }

    private function createConnectionMock(AMQPChannel $channel): AmqpConnection
    {
        $amqpConnection = $this->getMockBuilder(AMQPStreamConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['channel'])
            ->getMock();

        $amqpConnection->method('channel')->willReturn($channel);

        return new TestAmqpConnection($amqpConnection);
    }

    private function createChannelMock(array $methods): AMQPChannel
    {
        return $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods($methods)
            ->getMock();
    }
}
