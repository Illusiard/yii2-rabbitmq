<?php

namespace illusiard\rabbitmq\definitions\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\message\Envelope;

class ConsumeContext
{
    private Envelope $envelope;
    private MessageMeta $meta;
    private RabbitMqService $service;
    private ConsumerInterface $consumer;
    private bool $stopRequested;

    public function __construct(
        Envelope $envelope,
        MessageMeta $meta,
        RabbitMqService $service,
        ConsumerInterface $consumer,
        bool $stopRequested = false
    ) {
        $this->envelope = $envelope;
        $this->meta = $meta;
        $this->service = $service;
        $this->consumer = $consumer;
        $this->stopRequested = $stopRequested;
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getMeta(): MessageMeta
    {
        return $this->meta;
    }

    public function getConsumer(): ConsumerInterface
    {
        return $this->consumer;
    }

    public function getService(): RabbitMqService
    {
        return $this->service;
    }

    public function isStopRequested(): bool
    {
        return $this->stopRequested;
    }
}
