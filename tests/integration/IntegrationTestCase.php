<?php

namespace illusiard\rabbitmq\tests\integration;

use PhpAmqpLib\Wire\AMQPTable;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Message\AMQPMessage;
use Throwable;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use yii\base\InvalidConfigException;

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
            if ($this->envFlagEnabled('RABBITMQ_REQUIRED')) {
                $this->fail($message);
            }
            $this->markTestSkipped($message);
        }
    }

    protected function tearDown(): void
    {
        $this->cleanupResources();

        try {
            $this->service->getConnection()->close();
        } catch (Throwable) {
        }

        parent::tearDown();
    }

    protected function createService(array $overrides = []): RabbitMqService
    {
        $config = array_merge($this->getRabbitConfig(), $overrides);
        return new RabbitMqService($config);
    }

    /**
     * @param RabbitMqService $service
     * @return void
     * @throws InvalidConfigException
     */
    protected function setService(RabbitMqService $service): void
    {
        $this->service = $service;
        Yii::$app?->set('rabbitmq', $service);
    }

    protected function pingRabbit(): bool
    {
        try {
            $result = $this->service->ping(1);
            if (!$result) {
                $this->lastPingError = $this->service->getLastError();
            }
            return $result;
        } catch (Throwable $e) {
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

    /**
     * @return AMQPChannel
     * @throws InvalidConfigException
     */
    protected function getChannel(): AMQPChannel
    {
        return $this->service->getConnection()->getAmqpConnection()->channel();
    }

    /**
     * @param string $suffix
     * @return string
     * @throws Throwable
     */
    protected function uniqueName(string $suffix): string
    {
        $random = bin2hex(random_bytes(4));
        return 'illusiard_test_' . $suffix . '_' . $random;
    }

    /**
     * @param string $name
     * @param string $type
     * @param bool $durable
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @param string $name
     * @param bool $durable
     * @param array $arguments
     * @return void
     * @throws InvalidConfigException
     */
    protected function declareQueue(string $name, bool $durable = true, array $arguments = []): void
    {
        $channel = $this->getChannel();
        try {
            if (!empty($arguments)) {
                $channel->queue_declare($name, false, $durable, false, false, false, new AMQPTable($arguments));
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

    /**
     * @param string $queue
     * @param string $exchange
     * @param string $routingKey
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routingKey
     * @param array $properties
     * @param array $headers
     * @return void
     * @throws InvalidConfigException
     */
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
                $properties['application_headers'] = new AMQPTable($headers);
            }
            $message = new AMQPMessage($body, $properties);
            $channel->basic_publish($message, $exchange, $routingKey);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    /**
     * @param string $queue
     * @param bool $ack
     * @return ?AMQPMessage
     * @throws InvalidConfigException
     */
    protected function basicGet(string $queue, bool $ack = false): ?AMQPMessage
    {
        $channel = $this->getChannel();
        try {
            return $channel->basic_get($queue, $ack);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    /**
     * @param string $queue
     * @param int $timeoutSec
     * @return ?AMQPMessage
     * @throws InvalidConfigException
     */
    protected function waitForMessage(string $queue, int $timeoutSec = 5): ?AMQPMessage
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        while (microtime(true) < $deadline) {
            $message = $this->basicGet($queue);
            if ($message !== null) {
                return $message;
            }
            usleep(200000);
        }

        return null;
    }

    /**
     * @param string $queue
     * @param int $expected
     * @param int $timeoutSec
     * @return bool
     * @throws InvalidConfigException
     */
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

    /**
     * @param string $queue
     * @return int
     * @throws InvalidConfigException
     */
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
        } catch (Throwable) {
            return;
        }

        try {
            foreach (array_keys($this->queues) as $queue) {
                try {
                    $channel->queue_delete($queue);
                } catch (Throwable) {
                }
            }

            foreach (array_keys($this->exchanges) as $exchange) {
                try {
                    $channel->exchange_delete($exchange);
                } catch (Throwable) {
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
