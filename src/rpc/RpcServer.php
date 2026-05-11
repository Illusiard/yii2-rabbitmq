<?php

namespace illusiard\rabbitmq\rpc;

use InvalidArgumentException;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\helpers\FileHelper;
use yii\base\InvalidConfigException;

class RpcServer
{
    private ?string $readyLockFile = null;

    private RabbitMqService $service;
    private RpcAmqpConnectionResolver $connectionResolver;

    public function __construct(RabbitMqService $service)
    {
        $this->service = $service;
        $this->connectionResolver = new RpcAmqpConnectionResolver();
    }

    /**
     * @param string $queue
     * @param $handler
     * @return void
     * @throws Throwable
     * @throws InvalidConfigException
     */
    public function serve(string $queue, $handler): void
    {
        if (is_string($handler)) {
            $handler = Yii::createObject($handler);
        }

        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Handler must be callable and return Envelope.');
        }

        $amqpConnection = $this->connectionResolver->resolve($this->service);

        $channel = $amqpConnection->channel();
        $channel->basic_consume(
            $queue,
            '',
            false,
            false,
            false,
            false,
            function ($message) use ($handler) {
                $properties = $message->get_properties();
                $headers = [];
                if (isset($properties['application_headers']) && $properties['application_headers'] instanceof AMQPTable) {
                    $headers = $properties['application_headers']->getNativeData();
                }

                $meta = [
                    'headers' => $headers,
                    'properties' => $properties,
                ];

                $replyTo = $properties['reply_to'] ?? null;
                $correlationId = $properties['correlation_id'] ?? null;

                if (!$replyTo) {
                    Yii::error(ErrorCode::CONSUME_FAILED . ' RPC request missing reply_to.', 'rabbitmq');
                    $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                    return;
                }

                try {
                    $env = $this->service->decodeEnvelope($message->getBody(), $meta);
                    $response = $handler($env);
                    if (!$response instanceof Envelope) {
                        throw new RuntimeException('RPC handler must return Envelope.');
                    }

                    if ($correlationId) {
                        $response = $response->withCorrelationId($correlationId);
                    }

                    $this->service->publishEnvelope($response, '', $replyTo);
                    $message->getChannel()->basic_ack($message->getDeliveryTag());
                } catch (Throwable $e) {
                    $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::HANDLER_FAILED;
                    Yii::error($code . ' exception=' . get_class($e), 'rabbitmq');
                    $message->getChannel()->basic_reject($message->getDeliveryTag(), false);
                }
            }
        );

        if ($this->readyLockFile !== null && $this->readyLockFile !== '') {
            FileHelper::atomicWrite($this->readyLockFile, '');
        }

        try {
            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
            if ($this->readyLockFile !== null && $this->readyLockFile !== '') {
                FileHelper::removeFileQuietly($this->readyLockFile);
            }
        }
    }

    public function setReadyLockFile(?string $path): void
    {
        $this->readyLockFile = $path;
    }
}
