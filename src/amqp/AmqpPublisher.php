<?php

namespace illusiard\rabbitmq\amqp;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Exception\AMQPTimeoutException;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use Yii;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\exceptions\PublishException;

class AmqpPublisher implements PublisherInterface
{
    private AmqpConnection $connection;
    private ?AMQPChannel $channel = null;
    private bool $confirm;
    private bool $mandatory;
    private int $publishTimeout;
    private bool $acked = false;
    private bool $nacked = false;
    private ?array $returned = null;

    public function __construct(AmqpConnection $connection, array $config = [])
    {
        $this->connection = $connection;
        $this->confirm = (bool)($config['confirm'] ?? false);
        $this->mandatory = (bool)($config['mandatory'] ?? false);
        $this->publishTimeout = (int)($config['publishTimeout'] ?? 5);
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

        $message = new AMQPMessage($body, $properties);
        $this->resetPublishState();

        $channel = $this->getChannel();

        try {
            $channel->basic_publish($message, $exchange, $routingKey, $this->mandatory);
        } catch (\Throwable $e) {
            $this->resetChannel();
            throw new PublishException('Publish failed: ' . $e->getMessage(), 0, $e);
        }

        if ($this->confirm || $this->mandatory) {
            $this->waitForPublish($channel);
        }

        if ($this->returned !== null) {
            Yii::warning(
                'Message returned: ' . $this->returned['reply_code'] . ' ' . $this->returned['reply_text'],
                'rabbitmq'
            );
            throw new PublishException('Message was returned by broker (unroutable).');
        }

        if ($this->confirm) {
            if ($this->nacked) {
                throw new PublishException('Message was NACKed by broker.');
            }
            if (!$this->acked) {
                throw new PublishException('Publish confirm timeout after ' . $this->publishTimeout . ' seconds.');
            }
        }
    }

    private function getChannel(): AMQPChannel
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            return $this->channel;
        }

        $this->channel = $this->connection->getAmqpConnection()->channel();

        if ($this->confirm) {
            $this->channel->confirm_select();
            $this->channel->set_ack_handler(function () {
                $this->acked = true;
            });
            $this->channel->set_nack_handler(function () {
                $this->nacked = true;
            });
        }

        if ($this->mandatory) {
            $this->channel->set_return_listener(function (
                $replyCode,
                $replyText,
                $exchange,
                $routingKey,
                $message
            ) {
                $this->returned = [
                    'reply_code' => $replyCode,
                    'reply_text' => $replyText,
                    'exchange' => $exchange,
                    'routing_key' => $routingKey,
                ];
            });
        }

        return $this->channel;
    }

    private function waitForPublish(AMQPChannel $channel): void
    {
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

            if ($this->returned !== null) {
                return;
            }
            if ($this->confirm && ($this->acked || $this->nacked)) {
                return;
            }
        }
    }

    private function resetPublishState(): void
    {
        $this->acked = false;
        $this->nacked = false;
        $this->returned = null;
    }

    private function resetChannel(): void
    {
        if ($this->channel !== null && $this->channel->is_open()) {
            $this->channel->close();
        }
        $this->channel = null;
    }
}
