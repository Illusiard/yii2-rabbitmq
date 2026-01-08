<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\exceptions\PublishException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class AmqpPublisher implements PublisherInterface
{
    private AmqpConnection $connection;
    private ?AMQPChannel $channel = null;
    private bool $confirm;
    private bool $mandatory;
    private int $publishTimeout;
    private PublishConfirmTracker $tracker;
    private ?array $uncorrelatedReturn = null;

    public function __construct(AmqpConnection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->confirm = (bool)($config['confirm'] ?? false);
        $this->mandatory = (bool)($config['mandatory'] ?? false);
        $this->publishTimeout = (int)($config['publishTimeout'] ?? 5);
        $this->tracker = new PublishConfirmTracker();
    }

    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        if (!empty($headers)) {
            $properties['application_headers'] = new AMQPTable($headers);
        }

        if (!isset($properties['message_id']) || $properties['message_id'] === '') {
            $properties['message_id'] = $this->generateMessageId();
        }

        $messageId = (string)$properties['message_id'];
        $correlationId = isset($properties['correlation_id']) ? (string)$properties['correlation_id'] : null;
        $message = new AMQPMessage($body, $properties);

        $channel = $this->getChannel();
        $seqNo = null;
        $shouldTrack = $this->confirm || $this->mandatory;
        if ($shouldTrack) {
            $seqNo = $this->resolvePublishSeqNo($channel);
            $this->tracker->register($seqNo, $messageId, microtime(true));
        }

        try {
            $channel->basic_publish($message, $exchange, $routingKey, $this->mandatory);
        } catch (\Throwable $e) {
            if ($seqNo !== null) {
                $this->tracker->remove($seqNo);
            }
            $this->resetChannel();
            $this->logPublishError(
                ErrorCode::PUBLISH_FAILED,
                'Publish failed: ' . $e->getMessage(),
                $messageId,
                $correlationId,
                $exchange,
                $routingKey
            );
            throw new PublishException('Publish failed: ' . $e->getMessage(), ErrorCode::PUBLISH_FAILED, 0, $e);
        }

        try {
            if ($shouldTrack && $seqNo !== null) {
                $this->waitForPublish($channel, $seqNo, $messageId, $correlationId, $exchange, $routingKey);
            }
        } finally {
            if ($seqNo !== null) {
                $this->tracker->remove($seqNo);
            }
        }
    }

    private function getChannel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        if ($this->tracker->count() > 0) {
            Yii::warning('Publish channel was reset with inflight messages; clearing pending confirms.', 'rabbitmq');
            $this->tracker->clear();
        }
        $this->uncorrelatedReturn = null;

        $this->channel = $this->connection->getAmqpConnection()->channel();

        $this->installChannelListeners($this->channel);

        return $this->channel;
    }

    private function resetChannel(): void
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            $this->channel->close();
        }
        $this->channel = null;
    }

    //костыль из-за разных версий библиотек
    private function normalizeConfirmTag($arg): int
    {
        if (is_int($arg)) {
            return $arg;
        }
        if (is_string($arg) && ctype_digit($arg)) {
            return (int)$arg;
        }

        if ($arg instanceof AMQPMessage) {
            $messageId = null;

            try {
                $messageId = $arg->get('message_id');
            } catch (\Throwable $e) {
                // ignore
            }

            if ($messageId) {
                $seqNo = $this->tracker->findSeqNoByMessageId((string)$messageId);
                if ($seqNo !== null) {
                    return $seqNo;
                }
            }

            throw new \RuntimeException('Confirm callback passed AMQPMessage without correlatable message_id.');
        }

        throw new \RuntimeException('Unexpected confirm callback argument type: ' . (is_object($arg) ? get_class($arg) : gettype($arg)));
    }

    private function installChannelListeners(AMQPChannel $channel): void
    {
        if ($this->confirm) {
            $channel->confirm_select();
            $channel->set_ack_handler(function ($arg, $multiple = false) {
                $seqNo = $this->normalizeConfirmTag($arg);
                $this->tracker->markAck($seqNo, (bool)$multiple);
            });
            $channel->set_nack_handler(function ($arg, $multiple = false) {
                $seqNo = $this->normalizeConfirmTag($arg);
                $this->tracker->markNack($seqNo, (bool)$multiple);
            });
        }

        if ($this->mandatory) {
            $channel->set_return_listener(function (
                $replyCode,
                $replyText,
                $exchange,
                $routingKey,
                $message
            ) {
                $returnInfo = [
                    'reply_code' => $replyCode,
                    'reply_text' => $replyText,
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                ];

                $messageId = $this->resolveMessageId($message);
                if ($messageId === null || $messageId === '') {
                    $this->uncorrelatedReturn = $returnInfo;
                    Yii::warning(
                        ErrorCode::PUBLISH_UNROUTABLE_UNCORRELATED . ' Message returned without message_id.',
                        'rabbitmq'
                    );
                    return;
                }

                $seqNo = $this->tracker->markReturned($messageId, $returnInfo);
                if ($seqNo === null) {
                    $this->uncorrelatedReturn = $returnInfo;
                    Yii::warning(
                        ErrorCode::PUBLISH_UNROUTABLE_UNCORRELATED . ' Message returned but not found in inflight map.',
                        'rabbitmq'
                    );
                }
            });
        }
    }

    private function waitForPublish(
        AMQPChannel $channel,
        int $seqNo,
        string $messageId,
        ?string $correlationId,
        string $exchange,
        string $routingKey
    ): void {
        $deadline = microtime(true) + max(0, $this->publishTimeout);

        while (microtime(true) < $deadline) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                break;
            }

            try {
                if ($this->confirm) {
                    $channel->wait_for_pending_acks_returns($remaining);
                } else {
                    $channel->wait(null, false, $remaining);
                }
            } catch (AMQPTimeoutException $e) {
                break;
            }

            $state = $this->tracker->get($seqNo);
            if ($this->uncorrelatedReturn !== null) {
                $this->uncorrelatedReturn = null;
                $this->logPublishError(
                    ErrorCode::PUBLISH_UNROUTABLE_UNCORRELATED,
                    'Publish failed: unroutable return could not be correlated.',
                    $messageId,
                    $correlationId,
                    $exchange,
                    $routingKey
                );
                throw new PublishException(
                    'Message was returned by broker but could not be correlated.',
                    ErrorCode::PUBLISH_UNROUTABLE_UNCORRELATED
                );
            }
            if ($state !== null && $state['returned']) {
                $this->logPublishError(
                    ErrorCode::PUBLISH_UNROUTABLE,
                    'Publish failed: message was returned by broker.',
                    $messageId,
                    $correlationId,
                    $exchange,
                    $routingKey
                );
                throw new PublishException('Message was returned by broker (unroutable).', ErrorCode::PUBLISH_UNROUTABLE);
            }
            if ($this->confirm && $state !== null && $state['nacked']) {
                $this->logPublishError(
                    ErrorCode::PUBLISH_NACK,
                    'Publish failed: message was NACKed by broker.',
                    $messageId,
                    $correlationId,
                    $exchange,
                    $routingKey
                );
                throw new PublishException('Message was NACKed by broker.', ErrorCode::PUBLISH_NACK);
            }
            if ($this->confirm && $state !== null && $state['acked']) {
                return;
            }
        }

        if ($this->confirm) {
            $this->logPublishError(
                ErrorCode::PUBLISH_TIMEOUT,
                'Publish confirm timeout after ' . $this->publishTimeout . ' seconds.',
                $messageId,
                $correlationId,
                $exchange,
                $routingKey
            );
            throw new PublishException(
                'Publish confirm timeout after ' . $this->publishTimeout . ' seconds.',
                ErrorCode::PUBLISH_TIMEOUT
            );
        }
    }

    private function resolvePublishSeqNo(AMQPChannel $channel): int
    {
        if ($this->confirm && method_exists($channel, 'get_next_publish_seq_no')) {
            return (int)$channel->get_next_publish_seq_no();
        }

        return $this->tracker->nextLocalSeqNo();
    }

    private function resolveMessageId($message): ?string
    {
        if (is_object($message)) {
            if (method_exists($message, 'get')) {
                $value = $message->get('message_id');
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (method_exists($message, 'get_properties')) {
                $properties = $message->get_properties();
                if (is_array($properties) && isset($properties['message_id']) && is_string($properties['message_id'])) {
                    return $properties['message_id'];
                }
            }
        }

        return null;
    }

    private function generateMessageId(): string
    {
        try {
            return bin2hex(random_bytes(16));
        } catch (\Throwable $e) {
            return uniqid('msg_', true);
        }
    }

    private function logPublishError(
        string $code,
        string $message,
        ?string $messageId,
        ?string $correlationId,
        string $exchange,
        string $routingKey
    ): void {
        Yii::error(
            $code . ' ' . $message
            . ' message_id=' . ($messageId ?? '')
            . ' correlation_id=' . ($correlationId ?? '')
            . ' exchange=' . $exchange
            . ' routingKey=' . $routingKey,
            'rabbitmq'
        );
    }
}
