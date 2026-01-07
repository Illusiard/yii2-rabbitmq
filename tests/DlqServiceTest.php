<?php

namespace illusiard\rabbitmq\tests;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\dlq\DlqService;

class DlqServiceTest extends TestCase
{
    public function testReplayPublishesAndAcks(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $publisher->expects($this->exactly(2))->method('publish');

        $channel = new FakeDlqChannel([]);
        $channel->setMessages([
            new FakeDlqMessage('m1', 'c1', null),
            new FakeDlqMessage('m2', 'c2', null),
        ]);
        $channel->attachSelfToMessages();

        $connection = new FakeDlqConnection($publisher, $channel);
        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use ($connection) {
                unset($config);
                return $connection;
            },
        ]);

        $dlq = new DlqService($service);
        $count = $dlq->replay('dlq.queue', 'ex', 'rk', 10);

        $this->assertSame(2, $count);
        $this->assertSame(2, $channel->acks);
    }

    public function testPurgeCallsQueuePurge(): void
    {
        $publisher = $this->createMock(PublisherInterface::class);
        $channel = new FakeDlqChannel([]);
        $connection = new FakeDlqConnection($publisher, $channel);

        $service = new RabbitMqService([
            'connectionFactory' => function (array $config) use ($connection) {
                unset($config);
                return $connection;
            },
        ]);

        $dlq = new DlqService($service);
        $dlq->purge('dlq.queue');

        $this->assertSame(1, $channel->purges);
        $this->assertSame('dlq.queue', $channel->lastPurgeQueue);
    }
}

class FakeDlqConnection implements ConnectionInterface
{
    private PublisherInterface $publisher;
    private FakeDlqChannel $channel;
    private bool $connected = false;

    public function __construct(PublisherInterface $publisher, FakeDlqChannel $channel)
    {
        $this->publisher = $publisher;
        $this->channel = $channel;
    }

    public function connect(): void
    {
        $this->connected = true;
    }

    public function isConnected(): bool
    {
        return $this->connected;
    }

    public function close(): void
    {
        $this->connected = false;
    }

    public function getPublisher(): PublisherInterface
    {
        return $this->publisher;
    }

    public function getConsumer(): ConsumerInterface
    {
        throw new \RuntimeException('Not implemented.');
    }

    public function getAmqpConnection(): object
    {
        return new class($this->channel) {
            private FakeDlqChannel $channel;
            public function __construct(FakeDlqChannel $channel)
            {
                $this->channel = $channel;
            }
            public function channel(): FakeDlqChannel
            {
                return $this->channel;
            }
        };
    }
}

class FakeDlqChannel
{
    public int $acks = 0;
    public int $purges = 0;
    public string $lastPurgeQueue = '';
    private array $messages;

    public function __construct(array $messages)
    {
        $this->messages = $messages;
    }

    public function setMessages(array $messages): void
    {
        $this->messages = $messages;
    }

    public function attachSelfToMessages(): void
    {
        foreach ($this->messages as $message) {
            if (method_exists($message, 'setChannel')) {
                $message->setChannel($this);
            }
        }
    }

    public function basic_get(string $queue, bool $noAck)
    {
        unset($queue, $noAck);
        return array_shift($this->messages);
    }

    public function basic_ack($deliveryTag): void
    {
        unset($deliveryTag);
        $this->acks++;
    }

    public function basic_reject($deliveryTag, $requeue): void
    {
        unset($deliveryTag, $requeue);
    }

    public function queue_purge(string $queue): void
    {
        $this->purges++;
        $this->lastPurgeQueue = $queue;
    }

    public function is_open(): bool
    {
        return true;
    }

    public function close(): void
    {
    }
}

class FakeDlqMessage
{
    private string $messageId;
    private string $correlationId;
    private ?FakeDlqChannel $channel;

    public function __construct(string $messageId, string $correlationId, ?FakeDlqChannel $channel)
    {
        $this->messageId = $messageId;
        $this->correlationId = $correlationId;
        $this->channel = $channel;
    }

    public function setChannel(FakeDlqChannel $channel): void
    {
        $this->channel = $channel;
    }

    public function get_properties(): array
    {
        return [
            'message_id' => $this->messageId,
            'correlation_id' => $this->correlationId,
            'application_headers' => new AMQPTable(['x-death' => [['count' => 1]]]),
        ];
    }

    public function getBody(): string
    {
        return 'body-' . $this->messageId;
    }

    public function getChannel(): FakeDlqChannel
    {
        return $this->channel;
    }

    public function getDeliveryTag(): int
    {
        return 1;
    }
}
