<?php

namespace illusiard\rabbitmq\amqp;

use Exception;
use illusiard\rabbitmq\contracts\PublisherInterface;
use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Throwable;
use Yii;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\consume\TransportActionApplier;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;

class AmqpConsumer implements ConsumerInterface
{
    private AmqpConnection $connection;
    private int $reconnectAttempts;
    private int $reconnectDelaySeconds;
    /** @var callable|null */
    private $shouldStop;
    private bool $internalStopRequested = false;

    public function __construct(AmqpConnection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->reconnectAttempts = max(1, (int)($config['consumeReconnectAttempts'] ?? 3));
        $this->reconnectDelaySeconds = max(0, (int)($config['consumeReconnectDelaySeconds'] ?? 1));
    }

    public function setStopChecker(callable $shouldStop): void
    {
        $this->shouldStop = $shouldStop;
    }

    public function setManagedRetry(bool $enabled, array $policy, ?PublisherInterface $publisher): void
    {
    }

    /**
     * @param string $queue
     * @param callable $handler
     * @param int $prefetch
     * @return void
     * @throws Throwable
     */
    public function consume(string $queue, callable $handler, int $prefetch = 1): void
    {
        $attempts = 0;

        while (true) {
            try {
                $attempts++;
                if ($attempts > 1) {
                    Yii::warning('Reconnecting to RabbitMQ (attempt ' . ($attempts - 1) . ')', 'rabbitmq');
                } else {
                    Yii::info('Connecting to RabbitMQ', 'rabbitmq');
                }

                $this->consumeOnce($queue, $handler, $prefetch);
                return;
            } catch (Throwable $e) {
                if (!$this->isConnectionException($e) || $attempts >= $this->reconnectAttempts) {
                    $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
                    Yii::error($code . ' exception=' . get_class($e), 'rabbitmq');
                    throw $e;
                }

                Yii::warning(ErrorCode::CONNECTION_FAILED . ' Connection lost: exception=' . get_class($e), 'rabbitmq');
                $this->connection->close();
                if ($this->reconnectDelaySeconds > 0) {
                    sleep($this->reconnectDelaySeconds);
                }
            }
        }
    }

    /**
     * @param string $queue
     * @param callable $handler
     * @param int $prefetch
     * @return void
     * @throws Exception
     */
    private function consumeOnce(string $queue, callable $handler, int $prefetch): void
    {
        $channel = $this->connection->getAmqpConnection()->channel();
        $channel->basic_qos(0, $prefetch, null);

        $this->internalStopRequested = false;

        $consumerTag = $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function ($message) use ($handler, &$consumerTag) {
                $properties = $message->get_properties();
                $headers = [];
                if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
                    $headers = $properties['application_headers']->getNativeData();
                }

                $meta = [
                    'body' => $message->getBody(),
                    'delivery_tag' => $message->getDeliveryTag(),
                    'routing_key' => $message->getRoutingKey(),
                    'exchange' => $message->getExchange(),
                    'redelivered' => (bool)($message->delivery_info['redelivered'] ?? false),
                    'headers' => $headers,
                    'properties' => $properties,
                ];

                $result = $handler($message->getBody(), $meta);
                $normalized = ConsumeResult::normalizeHandlerResult($result);
                (new TransportActionApplier())->apply($normalized, $message);

                if ($normalized->getAction() === ConsumeResult::ACTION_STOP) {
                    $this->internalStopRequested = true;
                }

                $this->cancelIfNeeded($message->getChannel(), $consumerTag);
            }
        );

        try {
            while ($channel->is_consuming()) {
                if ($this->isStopRequested()) {
                    $this->cancelIfNeeded($channel, $consumerTag);
                }

                try {
                    $channel->wait(null, false, 1);
                } catch (AMQPTimeoutException) {
                    if ($this->isStopRequested()) {
                        $this->cancelIfNeeded($channel, $consumerTag);
                    }
                }
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
            $this->connection->close();
        }
    }

    private function cancelIfNeeded($channel, string $consumerTag): void
    {
        if ($this->isStopRequested()) {
            $channel->basic_cancel($consumerTag);
        }
    }

    private function isConnectionException(Throwable $e): bool
    {
        return $e instanceof AMQPConnectionClosedException
            || $e instanceof AMQPChannelClosedException
            || $e instanceof AMQPRuntimeException
            || $e instanceof AMQPIOException;
    }

    private function isStopRequested(): bool
    {
        if ($this->internalStopRequested) {
            return true;
        }

        return $this->shouldStop && ($this->shouldStop)();
    }
}
