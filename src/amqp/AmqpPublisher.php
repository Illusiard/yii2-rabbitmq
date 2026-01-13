<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;
use illusiard\rabbitmq\amqp\ReturnedMessage;
use illusiard\rabbitmq\amqp\ReturnSinkInterface;
use illusiard\rabbitmq\exceptions\PublishException;
use illusiard\rabbitmq\exceptions\ErrorCode;

class AmqpPublisher implements PublisherInterface
{
    private AmqpConnection $connection;
    private ?AMQPChannel $channel = null;
    private bool $confirm;
    private bool $mandatory;
    private bool $mandatoryStrict;
    private int $publishTimeout;
    private PublishConfirmTracker $tracker;
    private bool $returnHandlerEnabled;
    private ?ReturnHandlerInterface $returnHandler;
    private bool $returnSinkEnabled;
    private ?ReturnSinkInterface $returnSink;

    public function __construct(AmqpConnection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->confirm = (bool)($config['confirm'] ?? false);
        $this->mandatory = (bool)($config['mandatory'] ?? false);
        $this->mandatoryStrict = (bool)($config['mandatoryStrict'] ?? true);
        $this->publishTimeout = (int)($config['publishTimeout'] ?? 5);
        $this->tracker = new PublishConfirmTracker();
        $this->returnHandlerEnabled = (bool)($config['returnHandlerEnabled'] ?? true);
        $this->returnHandler = $this->resolveReturnHandler($config['returnHandler'] ?? null);
        $this->returnSinkEnabled = (bool)($config['returnSinkEnabled'] ?? true);
        $this->returnSink = $this->resolveReturnSink($config['returnSink'] ?? null);
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
        if ($this->confirm) {
            $seqNo = $this->resolvePublishSeqNo($channel);
            $this->tracker->register($seqNo, $messageId, microtime(true), $correlationId, $exchange, $routingKey);
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
            if ($this->confirm && $seqNo !== null) {
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
            $correlationId = null;

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

            try {
                $correlationId = $arg->get('correlation_id');
            } catch (\Throwable $e) {
                // ignore
            }

            if ($correlationId) {
                $seqNo = $this->tracker->findSeqNoByCorrelationId((string)$correlationId);
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
                if ($this->returnSink !== null && $this->returnSinkEnabled) {
                    $this->returnSink->onAck($seqNo, (bool)$multiple);
                }
            });
            $channel->set_nack_handler(function ($arg, $multiple = false) {
                $seqNo = $this->normalizeConfirmTag($arg);
                $this->tracker->markNack($seqNo, (bool)$multiple);
                if ($this->returnSink !== null && $this->returnSinkEnabled) {
                    $this->returnSink->onNack($seqNo, (bool)$multiple);
                }
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
                    'reply_code' => (int)$replyCode,
                    'reply_text' => (string)$replyText,
                    'exchange' => (string)$exchange,
                    'routing_key' => (string)$routingKey,
                ];

                $event = $this->dispatchReturn(
                    (int)$replyCode,
                    (string)$replyText,
                    (string)$exchange,
                    (string)$routingKey,
                    $message
                );

                if ($this->returnSink !== null && $this->returnSinkEnabled && $event !== null) {
                    $this->returnSink->onReturned($event);
                }

                if ($this->confirm) {
                    $messageId = $this->resolveMessageId($message);
                    $correlationId = $this->resolveCorrelationId($message);
                    $seqNo = null;

                    if ($messageId !== null && $messageId !== '') {
                        $seqNo = $this->tracker->markReturned($messageId, $returnInfo);
                    } elseif ($correlationId !== null && $correlationId !== '') {
                        $seqNo = $this->tracker->markReturnedByCorrelationId($correlationId, $returnInfo);
                    }

                    if ($seqNo === null) {
                        Yii::warning(
                            ErrorCode::PUBLISH_UNROUTABLE_UNCORRELATED . ' Message returned but could not be correlated.',
                            'rabbitmq'
                        );
                    }
                }
            });
        }
    }

    protected function waitForPublish(
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
            $this->throwIfReturned($state, $messageId, $correlationId, $exchange, $routingKey, $this->mandatoryStrict);
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

    private function throwIfReturned(
        ?array $state,
        string $messageId,
        ?string $correlationId,
        string $exchange,
        string $routingKey,
        bool $strict
    ): void {
        if ($state !== null && $state['returned']) {
            if (!$strict) {
                Yii::warning(
                    ErrorCode::PUBLISH_UNROUTABLE . ' Publish returned but strict mode disabled.',
                    'rabbitmq'
                );
                return;
            }
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

    private function resolveCorrelationId($message): ?string
    {
        if (is_object($message)) {
            if (method_exists($message, 'get')) {
                $value = $message->get('correlation_id');
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }

            if (method_exists($message, 'get_properties')) {
                $properties = $message->get_properties();
                if (is_array($properties) && isset($properties['correlation_id']) && is_string($properties['correlation_id'])) {
                    return $properties['correlation_id'];
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

    public function tick(float $timeout = 0.0): void
    {
        if ($this->channel === null || !$this->channel->is_open()) {
            return;
        }

        $timeout = max(0.0, $timeout);
        try {
            if ($this->confirm) {
                $this->channel->wait_for_pending_acks_returns($timeout);
            } else {
                $this->channel->wait(null, false, $timeout);
            }
        } catch (AMQPTimeoutException $e) {
            return;
        }
    }

    private function resolveReturnHandler($handler): ?ReturnHandlerInterface
    {
        if (!$this->returnHandlerEnabled || $handler === null) {
            return null;
        }

        if ($handler instanceof ReturnHandlerInterface) {
            return $handler;
        }

        $instance = Yii::createObject($handler);
        if (!$instance instanceof ReturnHandlerInterface) {
            throw new \InvalidArgumentException('returnHandler must implement ReturnHandlerInterface.');
        }

        return $instance;
    }

    private function resolveReturnSink($sink): ?ReturnSinkInterface
    {
        if (!$this->returnSinkEnabled || $sink === null) {
            return null;
        }

        if ($sink instanceof ReturnSinkInterface) {
            return $sink;
        }

        $instance = Yii::createObject($sink);
        if (!$instance instanceof ReturnSinkInterface) {
            throw new \InvalidArgumentException('returnSink must implement ReturnSinkInterface.');
        }

        return $instance;
    }

    public function drainReturns(): array
    {
        if ($this->returnSink !== null && method_exists($this->returnSink, 'drainReturns')) {
            return $this->returnSink->drainReturns();
        }

        return [];
    }

    private function dispatchReturn(
        int $replyCode,
        string $replyText,
        string $exchange,
        string $routingKey,
        $message
    ): ?ReturnedMessage {
        if (!$this->returnHandlerEnabled || $this->returnHandler === null) {
            $handler = null;
        } else {
            $handler = $this->returnHandler;
        }

        $properties = [];
        $headers = [];
        if (is_object($message) && method_exists($message, 'get_properties')) {
            $properties = $message->get_properties();
            if (is_array($properties) && isset($properties['application_headers'])
                && $properties['application_headers'] instanceof AMQPTable
            ) {
                $headers = $properties['application_headers']->getNativeData();
                $properties['application_headers'] = $headers;
            }
        }

        $bodySize = 0;
        if (is_object($message) && method_exists($message, 'getBody')) {
            $body = $message->getBody();
            if (is_string($body)) {
                $bodySize = strlen($body);
            }
        }

        $messageId = $this->resolveMessageId($message);
        $correlationId = null;
        if (is_array($properties) && isset($properties['correlation_id'])) {
            $correlationId = (string)$properties['correlation_id'];
        }

        $event = new ReturnedMessage(
            $messageId !== null ? (string)$messageId : null,
            $correlationId,
            $exchange,
            $routingKey,
            (int)$replyCode,
            (string)$replyText,
            $headers,
            is_array($properties) ? $properties : [],
            $bodySize,
            microtime(true)
        );

        if ($handler !== null) {
            $handler->handle($event);
        }

        return $event;
    }
}
