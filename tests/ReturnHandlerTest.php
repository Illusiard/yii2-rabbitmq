<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\amqp\AmqpPublisher;
use illusiard\rabbitmq\amqp\ReturnedMessage;
use illusiard\rabbitmq\tests\fixtures\RecordingReturnHandler;
use illusiard\rabbitmq\tests\fixtures\TestAmqpConnection;

class ReturnHandlerTest extends TestCase
{
    public function testReturnHandlerReceivesReturnedMessage(): void
    {
        $handler = new RecordingReturnHandler();
        /** @var callable|null $returnListener */
        $returnListener = null;

        $channel = $this->getMockBuilder(AMQPChannel::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['basic_publish', 'set_return_listener'])
            ->getMock();

        $channel->method('set_return_listener')->willReturnCallback(function ($listener) use (&$returnListener) {
            $returnListener = $listener;
        });

        $amqp = $this->getMockBuilder(AMQPStreamConnection::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['channel'])
            ->getMock();
        $amqp->method('channel')->willReturn($channel);

        $connection = new TestAmqpConnection($amqp);

        $publisher = new AmqpPublisher($connection, [
            'mandatory' => true,
            'confirm' => false,
            'returnHandler' => $handler,
            'returnHandlerEnabled' => true,
        ]);

        $publisher->publish('body', 'ex', 'rk', [
            'message_id' => 'msg-1',
            'correlation_id' => 'corr-1',
        ], [
            'x-trace-id' => 't-1',
        ]);

        $this->assertIsCallable($returnListener);

        $message = new AMQPMessage('payload', [
            'message_id' => 'msg-1',
            'correlation_id' => 'corr-1',
            'application_headers' => new AMQPTable(['x-trace-id' => 't-1']),
        ]);

        $returnListener(312, 'NO_ROUTE', 'ex', 'rk', $message);

        $this->assertInstanceOf(ReturnedMessage::class, $handler->event);
        $this->assertSame('msg-1', $handler->event->messageId);
        $this->assertSame('corr-1', $handler->event->correlationId);
        $this->assertSame('ex', $handler->event->exchange);
        $this->assertSame('rk', $handler->event->routingKey);
        $this->assertSame(312, $handler->event->replyCode);
        $this->assertSame('NO_ROUTE', $handler->event->replyText);
        $this->assertSame(['x-trace-id' => 't-1'], $handler->event->headers);
        $this->assertSame(['x-trace-id' => 't-1'], $handler->event->properties['application_headers']);
        $this->assertSame(7, $handler->event->bodySize);
    }
}
