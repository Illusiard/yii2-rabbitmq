<?php

namespace illusiard\rabbitmq\tests\integration;

use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\amqp\AmqpConnection;

/**
 * @group integration
 */
class IntegrationTestCase extends TestCase
{
    protected RabbitMqService $service;
    private array $queues = [];
    private array $exchanges = [];
    private ?string $lastPingError = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = $this->createService();
        $this->setService($this->service);

        if (!$this->pingRabbit()) {
            $message = 'RabbitMQ is not available at ' . $this->getConnectionDsnForMessage();
            if ($this->lastPingError) {
                $message .= ' (' . $this->lastPingError . ')';
            }
            $this->markTestSkipped($message);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupResources();

        try {
            $this->service->getConnection()->close();
        } catch (\Throwable $e) {
        }

        parent::tearDown();
    }

    protected function createService(array $overrides = []): RabbitMqService
    {
        $config = array_merge($this->getRabbitConfig(), $overrides);
        return new RabbitMqService($config);
    }

    protected function setService(RabbitMqService $service): void
    {
        $this->service = $service;
        if (Yii::$app) {
            Yii::$app->set('rabbitmq', $service);
        }
    }

    protected function pingRabbit(): bool
    {
        try {
            $result = $this->service->ping(1);
            if (!$result) {
                $this->lastPingError = $this->service->getLastError();
            }
            return $result;
        } catch (\Throwable $e) {
            $this->lastPingError = get_class($e) . ': ' . $e->getMessage();
            return false;
        }
    }

    protected function getConnectionDsnForMessage(): string
    {
        $config = $this->getRabbitConfig();
        return sprintf(
            '%s:%d vhost=%s user=%s',
            $config['host'],
            $config['port'],
            $config['vhost'],
            $config['user']
        );
    }

    protected function getChannel(): AMQPChannel
    {
        $connection = $this->service->getConnection();
        if ($connection instanceof AmqpConnection) {
            return $connection->getAmqpConnection()->channel();
        }

        $amqp = $connection->getAmqpConnection();
        return $amqp->channel();
    }

    protected function uniqueName(string $suffix): string
    {
        $random = bin2hex(random_bytes(4));
        return 'illusiard_test_' . $suffix . '_' . $random;
    }

    protected function declareExchange(string $name, string $type = 'direct', bool $durable = true): void
    {
        $channel = $this->getChannel();
        try {
            $channel->exchange_declare($name, $type, false, $durable, false);
            $this->exchanges[$name] = true;
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function declareQueue(string $name, bool $durable = true, array $arguments = []): void
    {
        $channel = $this->getChannel();
        try {
            if (!empty($arguments)) {
                $channel->queue_declare($name, false, $durable, false, false, false, new \PhpAmqpLib\Wire\AMQPTable($arguments));
            } else {
                $channel->queue_declare($name, false, $durable, false, false);
            }
            $this->queues[$name] = true;
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function bindQueue(string $queue, string $exchange, string $routingKey): void
    {
        $channel = $this->getChannel();
        try {
            $channel->queue_bind($queue, $exchange, $routingKey);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function publishRaw(
        string $body,
        string $exchange,
        string $routingKey,
        array $properties = [],
        array $headers = []
    ): void {
        $channel = $this->getChannel();
        try {
            if (!empty($headers)) {
                $properties['application_headers'] = new \PhpAmqpLib\Wire\AMQPTable($headers);
            }
            $message = new AMQPMessage($body, $properties);
            $channel->basic_publish($message, $exchange, $routingKey);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function basicGet(string $queue, bool $ack = false): ?\PhpAmqpLib\Message\AMQPMessage
    {
        $channel = $this->getChannel();
        try {
            $message = $channel->basic_get($queue, $ack);
            return $message;
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function waitForMessage(string $queue, int $timeoutSec = 5): ?\PhpAmqpLib\Message\AMQPMessage
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        while (microtime(true) < $deadline) {
            $message = $this->basicGet($queue, false);
            if ($message !== null) {
                return $message;
            }
            usleep(200000);
        }

        return null;
    }

    protected function waitForQueueCount(string $queue, int $expected, int $timeoutSec = 5): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        while (microtime(true) < $deadline) {
            $count = $this->getQueueCount($queue);
            if ($count === $expected) {
                return true;
            }
            usleep(200000);
        }

        return false;
    }

    protected function waitForFileExists(string $path, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    protected function waitForFileMissing(string $path, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            if (!is_file($path)) {
                return true;
            }
            usleep(10_000);
        }

        return !is_file($path);
    }

    protected function getQueueCount(string $queue): int
    {
        $channel = $this->getChannel();
        try {
            $data = $channel->queue_declare($queue, true);
            return is_array($data) ? (int)$data[1] : 0;
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function envFlagEnabled(string $name): bool
    {
        $value = getenv($name);
        return $value === '1' || $value === 'true' || $value === 'yes';
    }

    private function cleanupResources(): void
    {
        if (empty($this->queues) && empty($this->exchanges)) {
            return;
        }

        try {
            $channel = $this->getChannel();
        } catch (\Throwable $e) {
            return;
        }

        try {
            foreach (array_keys($this->queues) as $queue) {
                try {
                    $channel->queue_delete($queue);
                } catch (\Throwable $e) {
                }
            }

            foreach (array_keys($this->exchanges) as $exchange) {
                try {
                    $channel->exchange_delete($exchange);
                } catch (\Throwable $e) {
                }
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    protected function getRabbitConfig(): array
    {
        return [
            'host' => getenv('RABBIT_HOST') ?: 'localhost',
            'port' => getenv('RABBIT_PORT') ? (int)getenv('RABBIT_PORT') : 5672,
            'user' => getenv('RABBIT_USER') ?: 'guest',
            'password' => getenv('RABBIT_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBIT_VHOST') ?: '/',
            'heartbeat' => 30,
            'readWriteTimeout' => 3,
            'connectionTimeout' => 3,
            'confirm' => false,
            'mandatory' => false,
            'publishTimeout' => 5,
            'managedRetry' => false,
            'retryPolicy' => [],
            'publishMiddlewares' => [],
            'consumeMiddlewares' => [],
        ];
    }
}
