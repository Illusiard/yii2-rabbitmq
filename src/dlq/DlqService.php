<?php

namespace illusiard\rabbitmq\dlq;

use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class DlqService
{
    private RabbitMqService $service;

    public function __construct(RabbitMqService $service)
    {
        $this->service = $service;
    }

    public function inspect(string $queue, int $limit = 10): array
    {
        $connection = $this->service->getConnection();
        if (!method_exists($connection, 'getAmqpConnection')) {
            throw new RabbitMqException('DLQ inspect requires AMQP connection.', ErrorCode::DLQ_FAILED);
        }

        $channel = $connection->getAmqpConnection()->channel();
        $items = [];

        try {
            for ($i = 0; $i < $limit; $i++) {
                $message = $channel->basic_get($queue, true);
                if ($message === null) {
                    break;
                }

                $properties = $message->get_properties();
                $headers = [];
                if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
                    $headers = $properties['application_headers']->getNativeData();
                }

                $item = [
                    'body' => $message->getBody(),
                    'headers' => $headers,
                    'properties' => $properties,
                    'meta' => [
                        'routing_key' => $message->getRoutingKey(),
                        'exchange' => $message->getExchange(),
                        'redelivered' => $message->getRedelivered(),
                        'x-death' => $headers['x-death'] ?? null,
                    ],
                ];

                $contentType = $properties['content_type'] ?? '';
                if (is_string($contentType) && strpos($contentType, 'json') !== false) {
                    try {
                        $env = $this->service->decodeEnvelope($message->getBody(), [
                            'headers' => $headers,
                            'properties' => $properties,
                        ]);
                        $item['decodedEnvelope'] = $env->toArray();
                    } catch (\Throwable $e) {
                    }
                }

                $items[] = $item;
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }

        return $items;
    }

    public function replay(string $fromQueue, string $toExchange, string $toRoutingKey, int $limit = 100): int
    {
        $connection = $this->service->getConnection();
        if (!method_exists($connection, 'getAmqpConnection')) {
            throw new RabbitMqException('DLQ replay requires AMQP connection.', ErrorCode::DLQ_FAILED);
        }

        $channel = $connection->getAmqpConnection()->channel();
        $count = 0;

        try {
            for ($i = 0; $i < $limit; $i++) {
                $message = $channel->basic_get($fromQueue, false);
                if ($message === null) {
                    break;
                }

                $properties = $message->get_properties();
                $headers = [];
                if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
                    $headers = $properties['application_headers']->getNativeData();
                }

                Yii::debug(
                    'DLQ replay: queue=' . $fromQueue
                    . ' exchange=' . $toExchange
                    . ' routingKey=' . $toRoutingKey
                    . ' message_id=' . ($properties['message_id'] ?? '')
                    . ' correlation_id=' . ($properties['correlation_id'] ?? ''),
                    'rabbitmq'
                );

                try {
                    $this->service->publishRawWithMiddlewares(
                        $message->getBody(),
                        $toExchange,
                        $toRoutingKey,
                        $properties,
                        $headers
                    );
                    $message->getChannel()->basic_ack($message->getDeliveryTag());
                    $count++;
                } catch (\Throwable $e) {
                    $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::DLQ_FAILED;
                    Yii::warning($code . ' ' . get_class($e) . ': ' . $e->getMessage(), 'rabbitmq');
                    $message->getChannel()->basic_reject($message->getDeliveryTag(), true);
                }
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }

        return $count;
    }

    public function purge(string $queue): void
    {
        $connection = $this->service->getConnection();
        if (!method_exists($connection, 'getAmqpConnection')) {
            throw new RabbitMqException('DLQ purge requires AMQP connection.', ErrorCode::DLQ_FAILED);
        }

        $channel = $connection->getAmqpConnection()->channel();

        try {
            $channel->queue_purge($queue);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }
}
