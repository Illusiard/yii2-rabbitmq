# illusiard/yii2-rabbitmq

Минимальный скелет расширения Yii2 RabbitMQ с отложенным подключением и интерфейсами-заглушками.

## Installation

```bash
composer require illusiard/yii2-rabbitmq
```

## Configuration

```php
return [
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\rabbitmq\components\RabbitMqService::class,
            'host' => '127.0.0.1',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
            'vhost' => '/',
            'heartbeat' => 60,
            'readWriteTimeout' => 3.0,
            'connectionTimeout' => 3.0,
            'ssl' => null,
            'confirm' => false,
            'mandatory' => false,
            'publishTimeout' => 5,
        ],
    ],
];
```

## Usage (publish)

```php
/** @var illusiard\rabbitmq\components\RabbitMqService $rabbit */
$rabbit = Yii::$app->get('rabbitmq');

$rabbit->publish('Hello', 'exchange', 'route.key', [
    'content_type' => 'text/plain',
], [
    'x-trace-id' => 'demo-1',
]);

$rabbit->consume('queue.name', function ($message) {
    // Handle message (stub)
});
```

Consumer доступен через консольную команду.

## Publisher confirms и mandatory

- `confirm` включает publisher confirms. Публикация ждёт ack/nack от брокера; при nack или таймауте выбрасывается `PublishException`.
- `mandatory` включает mandatory publish. Если сообщение unroutable, брокер отправит `basic.return`, и будет выброшен `PublishException`.
- `publishTimeout` задаёт сколько секунд ждать подтверждения/возврата.

Эти опции полезны, когда важна надёжная доставка. При включении возможны ошибки:
`PublishException` (timeout, nack, unroutable).

## Console consumer

```bash
./yii rabbitmq/consume queue.name app\\queues\\RabbitMqHandler --prefetch=1 --memoryLimitMb=256
```

Пример:

```php
<?php

namespace app\\queues;

class RabbitMqHandler
{
    public function __invoke(string $body, array $meta): bool
    {
        // Process message
        return true;
    }
}
```

## Topology setup

```php
return [
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\\rabbitmq\\components\\RabbitMqService::class,
            'topology' => [
                'options' => [
                    'retryExchange' => 'retry-exchange',
                    'strict' => true,
                    'dryRun' => false,
                ],
                'main' => [
                    [
                        'queue' => 'orders',
                        'exchange' => 'orders-exchange',
                        'routingKey' => 'orders',
                        'options' => [
                            'deadLetterRoutingKey' => 'orders.retry',
                        ],
                    ],
                ],
                'retry' => [
                    [
                        'queue' => 'orders.retry',
                        'ttlMs' => 60000,
                        'deadLetterExchange' => 'orders-exchange',
                        'deadLetterRoutingKey' => 'orders',
                    ],
                ],
                'dead' => [
                    [
                        'queue' => 'orders.dead',
                    ],
                ],
            ],
        ],
    ],
];
```

```bash
./yii rabbitmq/setup-topology
```

Dry-run and strict from CLI:

```bash
./yii rabbitmq/setup-topology --dryRun=1 --strict=1
```
