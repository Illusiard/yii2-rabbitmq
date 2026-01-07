<?php

namespace illusiard\rabbitmq\components;

use Yii;
use yii\base\Component;
use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\TopologyManager;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
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
    public array $topology = [];
    public bool $confirm = false;
    public bool $mandatory = false;
    public int $publishTimeout = 5;
    public bool $managedRetry = false;
    public array $retryPolicy = [];

    /** @var callable|null */
    public $connectionFactory;

    private ?ConnectionInterface $connection = null;
    private ?PublisherInterface $publisher = null;

    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void
    {
        try {
            $this->getPublisher()->publish($body, $exchange, $routingKey, $properties, $headers);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (PublishException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PublishException('Publish failed: ' . $e->getMessage(), 0, $e);
        }
    }

    public function getConnection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    public function getPublisher(): PublisherInterface
    {
        if ($this->publisher === null) {
            $this->publisher = $this->ensureConnection()->getPublisher();
        }

        return $this->publisher;
    }

    public function consume(string $queue, string $handlerFqcn, int $prefetch = 1): void
    {
        $handler = Yii::createObject($handlerFqcn);
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('Handler must be callable via __invoke.');
        }

        $consumer = $this->ensureConnection()->getConsumer();
        if ($consumer instanceof \illusiard\rabbitmq\amqp\AmqpConsumer) {
            $consumer->setManagedRetry($this->managedRetry, $this->retryPolicy, $this->getPublisher());
        }

        $consumer->consume($queue, $handler, $prefetch);
    }

    public function setupTopology(array $config): void
    {
        $connection = $this->ensureConnection();
        if (!$connection instanceof AmqpConnection) {
            throw new ConnectionException('Topology setup requires AmqpConnection.');
        }

        $options = $config['options'] ?? [];
        $manager = new TopologyManager($connection, $options);
        $manager->apply($config, $options);
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

        return new AmqpConnection($this->getConnectionConfig());
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
            'confirm' => $this->confirm,
            'mandatory' => $this->mandatory,
            'publishTimeout' => $this->publishTimeout,
        ];
    }
}
