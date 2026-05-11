<?php

namespace illusiard\rabbitmq\definitions\consume;

class MessageMetaFactory
{
    public static function fromTransportMeta(string $body, array $meta): MessageMeta
    {
        return new MessageMeta(
            self::arrayValue($meta, 'headers'),
            self::arrayValue($meta, 'properties'),
            $body,
            isset($meta['delivery_tag']) && is_int($meta['delivery_tag']) ? $meta['delivery_tag'] : null,
            isset($meta['routing_key']) && is_string($meta['routing_key']) ? $meta['routing_key'] : null,
            isset($meta['exchange']) && is_string($meta['exchange']) ? $meta['exchange'] : null,
            isset($meta['redelivered']) && $meta['redelivered']
        );
    }

    public static function toTransportMeta(MessageMeta $meta): array
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

    private static function arrayValue(array $meta, string $key): array
    {
        return isset($meta[$key]) && is_array($meta[$key]) ? $meta[$key] : [];
    }
}
