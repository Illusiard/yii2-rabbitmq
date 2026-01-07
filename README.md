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
            'heartbeat' => 30,
            'readWriteTimeout' => 3,
            'connectionTimeout' => 3,
            'ssl' => null,
            'confirm' => false,
            'mandatory' => false,
            'publishTimeout' => 5,
            'managedRetry' => false,
            'retryPolicy' => [],
            'serializer' => illusiard\\rabbitmq\\message\\JsonMessageSerializer::class,
            'publishMiddlewares' => [],
            'consumeMiddlewares' => [],
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

## Semantics / Gotchas

- Publisher confirms:
  - Гарантирует, что брокер подтвердил приём публикации на уровне канала (ack/nack).
  - НЕ гарантирует доставку consumer-у и успешную обработку сообщения.
  - Корреляция подтверждений важна: подтверждения сопоставляются по publish sequence no + message_id.
- mandatory + basic.return:
  - Возникает, когда сообщение unroutable (exchange существует, но нет подходящего binding).
  - Это отдельный класс ошибки от сетевых/канальных: публикация дошла до брокера, но была возвращена.
- delivery_mode / persistence:
  - Для сохранности при рестарте брокера нужны durable queue + persistent message (`delivery_mode=2`).
  - Пакет не выставляет `delivery_mode` автоматически — задавайте его в `properties` при публикации.
- Retry semantics:
  - Managed retry использует заголовок `x-retry-count`; `x-death` не является источником истины.
  - `x-retry-count` увеличивается при republish в retry-очередь.
  - Сообщение уходит в dead при `dead` решении, иначе `reject` приводит к `basic_reject(false)`.
- DLQ inspect semantics:
  - `dlq-inspect` по умолчанию НЕ удаляет сообщения (`basic.get` + `nack requeue=true`).
  - Разрушающий режим только с `--ack=1 --force=1`.
- Consumer fail-fast semantics:
  - Fatal исключения валят процесс (если `consumeFailFast=true`).
  - Recoverable исключения означают неуспешную обработку (`false`) и включают retry/dead правила.

## Profiles (multi-connection)

Если нужно несколько подключений, используйте `profiles` и `defaultProfile`:

```php
return [
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\rabbitmq\components\RabbitMqService::class,
            'defaultProfile' => 'default',
            'profiles' => [
                'default' => [
                    'host' => '127.0.0.1',
                    'port' => 5672,
                ],
                'backup' => [
                    'host' => '10.0.0.2',
                    'port' => 5672,
                    'vhost' => '/backup',
                ],
            ],
        ],
    ],
];
```

В коде:

```php
$rabbit = Yii::$app->get('rabbitmq');
$rabbit->forProfile('backup')->publish('Hello', 'exchange', 'route.key');
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
- `message_id` используется для корреляции `basic.return` с конкретной публикацией; если вернуть сообщение невозможно сопоставить, будет ошибка `PUBLISH_UNROUTABLE_UNCORRELATED`.

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

## Serialization & Envelope

Можно публиковать типизированные сообщения через Envelope и сериализатор:

```php
use illusiard\rabbitmq\message\Envelope;

$env = new Envelope(
    ['orderId' => 123],
    ['x-trace-id' => 'trace-1'],
    [],
    'order.created',
    'corr-1'
);

$rabbit->publishEnvelope($env, 'orders-exchange', 'orders.created');
```

Упрощенный JSON publish:

```php
$rabbit->publishJson(
    ['orderId' => 123],
    'orders-exchange',
    'orders.created',
    [
        'type' => 'order.created',
        'correlationId' => 'corr-1',
        'headers' => ['x-trace-id' => 'trace-1'],
    ]
);
```

Декодирование в handler:

```php
$env = Yii::$app->get('rabbitmq')->decodeEnvelope($meta['body'], $meta);
```

## Middlewares

Можно подключить middleware для publish/consume:

```php
'publishMiddlewares' => [
    illusiard\\rabbitmq\\middleware\\CorrelationIdMiddleware::class,
    illusiard\\rabbitmq\\middleware\\PublishLoggingMiddleware::class,
],
'consumeMiddlewares' => [
    illusiard\\rabbitmq\\middleware\\ConsumeLoggingMiddleware::class,
],
```

Встроенные middleware:

- `CorrelationIdMiddleware` — гарантирует `correlation_id` в свойствах и в Envelope.
- `PublishLoggingMiddleware` — логирует отправку без payload/body.
- `ConsumeLoggingMiddleware` — логирует start/end и ошибки handler-а без body.

## RPC (request/reply)

Client:

```php
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcClient;

$client = new RpcClient(Yii::$app->get('rabbitmq'));
$response = $client->call(
    new Envelope(['ping' => true], [], [], 'rpc.ping'),
    'rpc-exchange',
    'rpc.ping',
    5
);
```

Server:

```php
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\rpc\RpcServer;

$server = new RpcServer(Yii::$app->get('rabbitmq'));
$server->serve('rpc.queue', function (Envelope $request): Envelope {
    return new Envelope(['pong' => true], [], [], 'rpc.pong', $request->getCorrelationId());
});
```

## DLQ tools

Просмотр сообщений в DLQ:

```bash
./yii rabbitmq/dlq-inspect orders.dead --limit=10 --json=1
```

По умолчанию `dlq-inspect` безопасен и не удаляет сообщения (requeue=true).
Разрушающий режим включается только с `--ack=1 --force=1`.

Повторная отправка после исправления ошибки:

```bash
./yii rabbitmq/dlq-replay orders.dead --exchange=orders-exchange --routingKey=orders --limit=100
```

Очистка мусорной очереди:

```bash
./yii rabbitmq/dlq-purge orders.dead --force=1
```

## Troubleshooting / FAQ

Unroutable message (mandatory/basic.return):
- возникает при `mandatory=true`, если нет binding для routingKey на exchange
- это не сетевой сбой; проверьте topology и логи `PUBLISH_UNROUTABLE`

Confirm timeout:
- проверьте `publishTimeout`, network latency и нагрузку на брокер
- timeout не означает, что сообщение не принято — смотрите confirms и return события

Exchange/queue not declared:
- выполните `rabbitmq/setup-topology` или проверьте provisioning инфраструктуры

Permissions/vhost errors:
- проверьте `vhost`, права пользователя и учётные данные

Connection refused / heartbeat timeouts:
- проверьте доступность хоста/порта, firewall, а также `heartbeat` и таймауты

Consumer exits on exception:
- fatal исключения завершают процесс (fail-fast), recoverable возвращают `false` и запускают retry/dead
- настройте `consumeFailFast`, `fatalExceptionClasses`, `recoverableExceptionClasses`

Retry loops / attempts:
- managed retry опирается на `x-retry-count`, увеличиваемый при republish
- убедитесь, что политика соответствует ожидаемому числу попыток

DLQ inspect shows same message:
- `dlq-inspect` requeue=true, поэтому одно и то же сообщение видно повторно
- при одном запуске используйте fingerprint/dedup на стороне клиента, destructive режим только с `--ack=1 --force=1`

Consumer restart strategy:
- используйте supervisor/systemd/k8s и внешние healthchecks, чтобы перезапускать consumer при ошибках

## Error Codes

- CONFIG_INVALID: неверный формат конфигурации
- CONNECTION_FAILED: ошибка соединения с брокером
- CHANNEL_FAILED: ошибка открытия канала
- PUBLISH_FAILED: ошибка публикации
- PUBLISH_NACK: broker NACK
- PUBLISH_TIMEOUT: таймаут confirms
- PUBLISH_UNROUTABLE: сообщение unroutable
- PUBLISH_UNROUTABLE_UNCORRELATED: unroutable сообщение без корреляции
- CONSUME_FAILED: ошибка consumer pipeline
- HANDLER_FAILED: ошибка handler
- SERIALIZATION_FAILED: ошибка сериализации/десериализации
- RPC_TIMEOUT: таймаут RPC
- TOPOLOGY_INVALID: неверный формат topology
- DLQ_FAILED: ошибка DLQ операций

## Healthcheck

Команда проверяет соединение и канал, возвращает `OK` или `FAIL`:

```bash
./yii rabbitmq/healthcheck --profile=default --timeout=3 --json=0
```
