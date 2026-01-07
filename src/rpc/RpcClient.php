<?php

namespace illusiard\rabbitmq\rpc;

use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class RpcClient
{
    private RabbitMqService $service;
    private array $options;

    public function __construct(RabbitMqService $service, array $options = [])
    {
        $this->service = $service;
        $this->options = $options;
    }

    public function call(Envelope $request, string $exchange, string $routingKey, int $timeoutSec = 5): Envelope
    {
        $connection = $this->service->getConnection();
        if (!method_exists($connection, 'getAmqpConnection')) {
            throw new RabbitMqException('RPC requires AMQP connection.', ErrorCode::CONNECTION_FAILED);
        }

        try {
            $channel = $connection->getAmqpConnection()->channel();
            $queueData = $channel->queue_declare('', false, false, true, true);
            $replyQueue = is_array($queueData) ? (string)$queueData[0] : '';
        } catch (\Throwable $e) {
            throw new RabbitMqException('RPC channel failed: ' . $e->getMessage(), ErrorCode::CHANNEL_FAILED, 0, $e);
        }

        $correlationId = $request->getCorrelationId();
        if ($correlationId === null || $correlationId === '') {
            $correlationId = $this->generateCorrelationId();
            $request = $request->withCorrelationId($correlationId);
        }

        $request = $request
            ->withProperty('reply_to', $replyQueue)
            ->withProperty('correlation_id', $correlationId);

        $response = null;
        $consumerTag = null;

        $consumerTag = $channel->basic_consume(
            $replyQueue,
            '',
            false,
            true,
            true,
            false,
            function ($message) use (&$response, $correlationId) {
                $properties = $message->get_properties();
                $headers = [];
                if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
                    $headers = $properties['application_headers']->getNativeData();
                }

                $receivedCorrelationId = $properties['correlation_id'] ?? null;
                if ($receivedCorrelationId !== $correlationId) {
                    return;
                }

                $response = [
                    'body' => $message->getBody(),
                    'meta' => [
                        'headers' => $headers,
                        'properties' => $properties,
                    ],
                ];
            }
        );

        try {
            $this->service->publishEnvelope($request, $exchange, $routingKey);

            $deadline = microtime(true) + max(0, $timeoutSec);
            while ($response === null && microtime(true) < $deadline) {
                $remaining = $deadline - microtime(true);
                if ($remaining <= 0) {
                    break;
                }

                try {
                    $channel->wait(null, false, $remaining);
                } catch (AMQPTimeoutException $e) {
                    break;
                }
            }

            if ($response === null) {
                throw new RpcTimeoutException('RPC timeout after ' . $timeoutSec . ' seconds.');
            }

            return $this->service->decodeEnvelope($response['body'], $response['meta']);
        } finally {
            if ($consumerTag && $channel->is_open()) {
                $channel->basic_cancel($consumerTag);
            }
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    private function generateCorrelationId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('rpc_', true);
        }
    }
}
