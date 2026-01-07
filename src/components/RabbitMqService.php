<?php

namespace illusiard\rabbitmq\components;

use yii\base\Component;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\exceptions\ConnectionException;
use illusiard\rabbitmq\exceptions\PublishException;

class RabbitMqService extends Component
{
    public string $host = '127.0.0.1';
    public int $port = 5672;
    public string $user = 'guest';
    public string $password = 'guest';
    public string $vhost = '/';
    public int $heartbeat = 60;
    public float $readWriteTimeout = 3.0;
    public float $connectionTimeout = 3.0;
    public ?array $ssl = null;

    /** @var callable|null */
    public $connectionFactory;

    private ?ConnectionInterface $connection = null;

    public function publish(string $exchange, string $routingKey, string $body, array $properties = []): void
    {
        $connection = $this->ensureConnection();

        try {
            $connection->getPublisher()->publish($exchange, $routingKey, $body, $properties);
        } catch (\Throwable $e) {
            throw new PublishException('Publish failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function consume(string $queue, callable $handler, array $options = []): void
    {
        $connection = $this->ensureConnection();

        $connection->getConsumer()->consume($queue, $handler, $options);
    }

    public function getConnection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    private function ensureConnection(): ConnectionInterface
    {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            try {
                $connection->connect();
            } catch (\Throwable $e) {
                throw new ConnectionException('Connection failed: ' . $e->getMessage(), 0, $e);
            }
        }

        return $connection;
    }

    private function createConnection(): ConnectionInterface
    {
        if (is_callable($this->connectionFactory)) {
            $factory = $this->connectionFactory;

            return $factory($this->getConnectionConfig());
        }

        return new StubConnection($this->getConnectionConfig());
    }

    private function getConnectionConfig(): array
    {
        return [
            'host' => $this->host,
            'port' => $this->port,
            'user' => $this->user,
            'password' => $this->password,
            'vhost' => $this->vhost,
            'heartbeat' => $this->heartbeat,
            'readWriteTimeout' => $this->readWriteTimeout,
            'connectionTimeout' => $this->connectionTimeout,
            'ssl' => $this->ssl,
        ];
    }
}

class StubConnection implements ConnectionInterface
{
    private array $config;
    private bool $connected = false;
    private ?PublisherInterface $publisher = null;
    private ?ConsumerInterface $consumer = null;

    public function __construct(array $config)
    {
        $this->config = $config;
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
        if ($this->publisher === null) {
            $this->publisher = new StubPublisher($this->config);
        }

        return $this->publisher;
    }

    public function getConsumer(): ConsumerInterface
    {
        if ($this->consumer === null) {
            $this->consumer = new StubConsumer($this->config);
        }

        return $this->consumer;
    }
}

class StubPublisher implements PublisherInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function publish(string $exchange, string $routingKey, string $body, array $properties = []): void
    {
    }
}

class StubConsumer implements ConsumerInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function consume(string $queue, callable $handler, array $options = []): void
    {
    }
}
