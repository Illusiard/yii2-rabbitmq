<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMetaFactory;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use illusiard\rabbitmq\middleware\ConsumeMiddlewareInterface;
use yii\base\InvalidConfigException;

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

    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        $meta = MessageMetaFactory::toTransportMeta($context->getMeta());
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

    /**
     * @param string $body
     * @param array $meta
     * @return ConsumeContext
     * @throws InvalidConfigException
     */
    private function buildContext(string $body, array $meta): ConsumeContext
    {
        $envelope = $this->service->decodeEnvelope($body, $meta);

        return new ConsumeContext($envelope, MessageMetaFactory::fromTransportMeta($body, $meta), $this->service, $this->consumer);
    }
}
