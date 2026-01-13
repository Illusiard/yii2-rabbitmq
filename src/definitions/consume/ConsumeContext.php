<?php

namespace illusiard\rabbitmq\definitions\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\publisher\PublisherInterface;
use illusiard\rabbitmq\message\Envelope;

class ConsumeContext
{
    private Envelope $envelope;
    private array $meta;
    private RabbitMqService $service;
    private ?ConsumerInterface $consumer;
    private ?PublisherInterface $publisher;

    public function __construct(
        Envelope $envelope,
        array $meta,
        RabbitMqService $service,
        ?ConsumerInterface $consumer = null,
        ?PublisherInterface $publisher = null
    ) {
        $this->envelope = $envelope;
        $this->meta = $meta;
        $this->service = $service;
        $this->consumer = $consumer;
        $this->publisher = $publisher;
    }

    public function getEnvelope(): Envelope
    {
        return $this->envelope;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getService(): RabbitMqService
    {
        return $this->service;
    }

    public function getConsumer(): ?ConsumerInterface
    {
        return $this->consumer;
    }

    public function getPublisher(): ?PublisherInterface
    {
        return $this->publisher;
    }
}
