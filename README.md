# illusiard/yii2-rabbitmq

Расширение Yii2 RabbitMQ с отложенным соединением, подтверждением публикации и управляемыми handlers c ретраем.

## Quickstart

Установка:

```bash
composer require illusiard/yii2-rabbitmq
```

Конфигурация:

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
            'managedRetry' => false,
            'retryPolicy' => [],
        ],
    ],
];
```

Publish:

```php
/** @var illusiard\rabbitmq\components\RabbitMqService $rabbit */
$rabbit = Yii::$app->get('rabbitmq');

$rabbit->publish('Hello', 'exchange', 'route.key', [
    'content_type' => 'text/plain',
], [
    'x-trace-id' => 'demo-1',
]);
```

## Consumer

Запуск:

```bash
./yii rabbitmq/consume queue.name app\\queues\\RabbitMqHandler --prefetch=1 --memoryLimitMb=256
```

Handler:

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
./yii rabbitmq/setup-topology --dryRun=1 --strict=1
```

## Confirm/mandatory

- `confirm` включает publisher confirms: публикация ждёт ack/nack, при nack или таймауте будет `PublishException`.
- `mandatory` включает mandatory publish: unroutable сообщение вернётся через `basic.return`, будет `PublishException`.
- `publishTimeout` задаёт таймаут ожидания подтверждения/возврата.

Включайте эти опции, когда важна надёжная доставка и нужно явно получать ошибки доставки.

## Managed retry

Если `managedRetry=true`, то при `handler=false` выполняется `RetryDecider` и:

- `retry` — перепубликация в `retryQueue` через default exchange (`exchange=''`, `routingKey=queueName`), затем ACK
- `dead` — перепубликация в `deadQueue` (если указана), затем ACK
- `reject` — `basic_reject(false)`

Пример политики:

```php
'managedRetry' => true,
'retryPolicy' => [
    'maxAttempts' => 3,
    'retryQueues' => [
        ['name' => 'orders.retry.5s', 'ttlMs' => 5000],
        ['name' => 'orders.retry.30s', 'ttlMs' => 30000],
    ],
    'deadQueue' => 'orders.dead',
],
```

Помощник для повторных отправок (в handler):

```php
<?php

use illusiard\rabbitmq\retry\RetryDecider;

$decider = new RetryDecider();
$decision = $decider->decide($meta, [
    'maxAttempts' => 3,
    'retryQueues' => [
        ['name' => 'orders.retry.5s', 'ttlMs' => 5000],
        ['name' => 'orders.retry.30s', 'ttlMs' => 30000],
    ],
    'deadQueue' => 'orders.dead',
]);

// Если handler возвращает false, потребитель отклонит запрос.
return false;
```
