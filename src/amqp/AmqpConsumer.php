<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Wire\AMQPTable;
use PhpAmqpLib\Exception\AMQPChannelClosedException;
use PhpAmqpLib\Exception\AMQPConnectionClosedException;
use PhpAmqpLib\Exception\AMQPIOException;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use Yii;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\retry\RetryDecider;
use illusiard\rabbitmq\retry\RetryDecision;

class AmqpConsumer implements ConsumerInterface
{
    private AmqpConnection $connection;
    /** @var callable|null */
    private $shouldStop;
    private bool $managedRetry = false;
    private array $retryPolicy = [];
    private ?PublisherInterface $retryPublisher = null;
    private RetryDecider $retryDecider;

    public function __construct(AmqpConnection $connection)
    {
        $this->connection = $connection;
        $this->retryDecider = new RetryDecider();
    }

    public function setStopChecker(callable $shouldStop): void
    {
        $this->shouldStop = $shouldStop;
    }

    public function setManagedRetry(bool $enabled, array $policy, ?PublisherInterface $publisher): void
    {
        $this->managedRetry = $enabled;
        $this->retryPolicy = $policy;
        $this->retryPublisher = $publisher;
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
            } catch (\Throwable $e) {
                if (!$this->isConnectionException($e) || $attempts >= 3) {
                    Yii::error('Consumer stopped: ' . $e->getMessage(), 'rabbitmq');
                    throw $e;
                }

                Yii::warning('Connection lost: ' . $e->getMessage(), 'rabbitmq');
                $this->connection->close();
                sleep(1);
            }
        }
    }

    private function consumeOnce(string $queue, callable $handler, int $prefetch): void
    {
        $channel = $this->connection->getAmqpConnection()->channel();
        $channel->basic_qos(null, $prefetch, null);

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
                    'delivery_tag' => $message->getDeliveryTag(),
                    'routing_key' => $message->getRoutingKey(),
                    'exchange' => $message->getExchange(),
                    'redelivered' => $message->getRedelivered(),
                    'headers' => $headers,
                    'properties' => $properties,
                ];

                try {
                    $result = $handler($message->getBody(), $meta);
                } catch (\Throwable $e) {
                    Yii::error('Handler error: ' . $e->getMessage(), 'rabbitmq');
                    $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                    $this->cancelIfNeeded($message->getChannel(), $consumerTag);
                    if ($e instanceof \RuntimeException && $e->getMessage() === 'Memory limit exceeded.') {
                        throw $e;
                    }
                    return;
                }

                if ($result) {
                    $message->getChannel()->basic_ack($message->getDeliveryTag());
                } else {
                    if ($this->managedRetry) {
                        try {
                            $decision = $this->retryDecider->decide($meta, $this->retryPolicy);
                            $this->handleManagedDecision(
                                $decision,
                                $message->getBody(),
                                $meta,
                                function () use ($message) {
                                    $message->getChannel()->basic_ack($message->getDeliveryTag());
                                },
                                function () use ($message) {
                                    $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                                }
                            );
                        } catch (\Throwable $e) {
                            $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                            throw $e;
                        }
                    } else {
                        $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                    }
                }

                $this->cancelIfNeeded($message->getChannel(), $consumerTag);
            }
        );

        try {
            while ($channel->is_consuming()) {
                if ($this->shouldStop && ($this->shouldStop)()) {
                    $this->cancelIfNeeded($channel, $consumerTag);
                }

                try {
                    $channel->wait(null, false, 1);
                } catch (AMQPTimeoutException $e) {
                    if ($this->shouldStop && ($this->shouldStop)()) {
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
        if ($this->shouldStop && ($this->shouldStop)()) {
            $channel->basic_cancel($consumerTag);
        }
    }

    protected function handleManagedDecision(
        RetryDecision $decision,
        string $body,
        array $meta,
        callable $ack,
        callable $reject
    ): void {
        if ($decision->action === 'retry') {
            if (!$decision->retryQueue || !$this->retryPublisher) {
                Yii::warning('Retry requested but publisher or retry queue is missing.', 'rabbitmq');
                $reject();
                return;
            }

            Yii::info('Retrying message via queue: ' . $decision->retryQueue, 'rabbitmq');
            $this->republish($body, $decision->retryQueue, $meta);
            $ack();
            return;
        }

        if ($decision->action === 'dead') {
            if ($decision->retryQueue && $this->retryPublisher) {
                Yii::warning('Sending message to dead queue: ' . $decision->retryQueue, 'rabbitmq');
                $this->republish($body, $decision->retryQueue, $meta);
                $ack();
                return;
            }

            Yii::warning('Dead action requested but dead queue is missing.', 'rabbitmq');
            $reject();
            return;
        }

        $reject();
    }

    private function republish(string $body, string $queue, array $meta): void
    {
        try {
            $properties = isset($meta['properties']) && is_array($meta['properties']) ? $meta['properties'] : [];
            $headers = isset($meta['headers']) && is_array($meta['headers']) ? $meta['headers'] : [];
            $this->retryPublisher->publish($body, '', $queue, $properties, $headers);
        } catch (\Throwable $e) {
            Yii::error('Republish failed: ' . $e->getMessage(), 'rabbitmq');
            throw $e;
        }
    }

    private function isConnectionException(\Throwable $e): bool
    {
        return $e instanceof AMQPConnectionClosedException
            || $e instanceof AMQPChannelClosedException
            || $e instanceof AMQPRuntimeException
            || $e instanceof AMQPIOException;
    }
}
