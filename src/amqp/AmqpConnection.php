<?php

namespace illusiard\rabbitmq\amqp;

use Exception;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;

class AmqpConnection implements ConnectionInterface
{
    private array $config;
    private ?AMQPStreamConnection $connection = null;
    private ?PublisherInterface $publisher = null;
    private ?ConsumerInterface $consumer = null;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @return void
     * @throws Exception
     */
    public function connect(): void
    {
        if ($this->connection !== null && $this->connection->isConnected()) {
            return;
        }

        $context = null;
        if (!empty($this->config['ssl'])) {
            $context = stream_context_create([
                'ssl' => $this->config['ssl'],
            ]);
        }

        $this->connection = new AMQPStreamConnection(
            $this->config['host'],
            $this->config['port'],
            $this->config['user'],
            $this->config['password'],
            $this->config['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            $this->config['connectionTimeout'],
            $this->config['readWriteTimeout'],
            $context,
            false,
            $this->config['heartbeat']
        );
    }

    public function isConnected(): bool
    {
        return $this->connection !== null && $this->connection->isConnected();
    }

    /**
     * @return void
     * @throws Exception
     */
    public function close(): void
    {
        if ($this->connection !== null) {
            $this->connection->close();
            $this->connection = null;
        }
        $this->publisher = null;
        $this->consumer = null;
    }

    public function getPublisher(): PublisherInterface
    {
        if ($this->publisher === null) {
            $this->publisher = new AmqpPublisher($this, $this->config);
        }

        return $this->publisher;
    }

    public function getConsumer(): ConsumerInterface
    {
        if ($this->consumer === null) {
            $this->consumer = new AmqpConsumer($this);
        }

        return $this->consumer;
    }

    /**
     * @return AMQPStreamConnection
     * @throws Exception
     */
    public function getAmqpConnection(): AMQPStreamConnection
    {
        $this->connect();

        return $this->connection;
    }

    public function getConfig(): array
    {
        return $this->config;
    }
}
