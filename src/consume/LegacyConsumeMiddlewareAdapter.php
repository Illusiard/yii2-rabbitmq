<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use illusiard\rabbitmq\middleware\ConsumeMiddlewareInterface;

class LegacyConsumeMiddlewareAdapter implements MiddlewareInterface
{
    private ConsumeMiddlewareInterface $legacy;
    private RabbitMqService $service;
    private ConsumerInterface $consumer;
    private string $handlerClass;

    public function __construct(
        ConsumeMiddlewareInterface $legacy,
        RabbitMqService $service,
        ConsumerInterface $consumer,
        string $handlerClass
    ) {
        $this->legacy = $legacy;
        $this->service = $service;
        $this->consumer = $consumer;
        $this->handlerClass = $handlerClass;
    }

    public function process(ConsumeContext $context, callable $next)
    {
        $meta = $this->buildLegacyMeta($context->getMeta());
        $legacyContext = [
            'exchange' => null,
            'routingKey' => null,
            'queue' => $this->consumer->getQueue(),
            'handlerClass' => $this->handlerClass,
            'componentId' => $this->service->componentId,
            'timestamp' => time(),
        ];

        $nextLegacy = function (string $body, array $meta) use ($next): bool {
            $ctx = $this->buildContext($body, $meta);
            $result = $next($ctx);
            $normalized = ConsumeResult::normalizeHandlerResult($result);
            return $normalized->getAction() === ConsumeResult::ACTION_ACK;
        };

        $result = $this->legacy->handle((string)$context->getMeta()->getBody(), $meta, $legacyContext, $nextLegacy);

        return ConsumeResult::normalizeHandlerResult($result);
    }

    private function buildLegacyMeta(MessageMeta $meta): array
    {
        return [
            'body' => $meta->getBody(),
            'delivery_tag' => $meta->getDeliveryTag(),
            'routing_key' => $meta->getRoutingKey(),
            'exchange' => $meta->getExchange(),
            'redelivered' => $meta->isRedelivered(),
            'headers' => $meta->getHeaders(),
            'properties' => $meta->getProperties(),
        ];
    }

    private function buildContext(string $body, array $meta): ConsumeContext
    {
        $headers = isset($meta['headers']) && is_array($meta['headers']) ? $meta['headers'] : [];
        $properties = isset($meta['properties']) && is_array($meta['properties']) ? $meta['properties'] : [];
        $deliveryTag = isset($meta['delivery_tag']) && is_int($meta['delivery_tag']) ? $meta['delivery_tag'] : null;
        $routingKey = isset($meta['routing_key']) && is_string($meta['routing_key']) ? $meta['routing_key'] : null;
        $exchange = isset($meta['exchange']) && is_string($meta['exchange']) ? $meta['exchange'] : null;
        $redelivered = isset($meta['redelivered']) ? (bool)$meta['redelivered'] : false;

        $messageMeta = new MessageMeta($headers, $properties, $body, $deliveryTag, $routingKey, $exchange, $redelivered);
        $envelope = $this->service->decodeEnvelope($body, $meta);

        return new ConsumeContext($envelope, $messageMeta, $this->service, $this->consumer);
    }
}
