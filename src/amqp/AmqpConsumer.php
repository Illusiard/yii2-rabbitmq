<?php

namespace illusiard\rabbitmq\amqp;

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
    /** @var callable|null */
    private $shouldStop;
    private bool $internalStopRequested = false;

    public function __construct(AmqpConnection $connection)
    {
        $this->connection = $connection;
    }

    public function setStopChecker(callable $shouldStop): void
    {
        $this->shouldStop = $shouldStop;
    }

    public function setManagedRetry(bool $enabled, array $policy, ?\illusiard\rabbitmq\contracts\PublisherInterface $publisher): void
    {
    }

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
                if (!$this->isConnectionException($e) || $attempts >= 3) {
                    $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::CONSUME_FAILED;
                    Yii::error($code . ' ' . get_class($e) . ': ' . $e->getMessage(), 'rabbitmq');
                    throw $e;
                }

                Yii::warning(ErrorCode::CONNECTION_FAILED . ' Connection lost: ' . $e->getMessage(), 'rabbitmq');
                $this->connection->close();
                sleep(1);
            }
        }
    }

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
                } catch (AMQPTimeoutException $e) {
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
