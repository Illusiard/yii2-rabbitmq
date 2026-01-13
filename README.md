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
  - В режиме mandatory без confirm публикация не блокируется ожиданием (без publishTimeout).
  - Возвраты обрабатываются асинхронно через return handler и требуют tick() или event loop.
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

Consumer class:

```php
<?php

namespace app\\services\\rabbitmq\\consumers;

use illusiard\\rabbitmq\\consumer\\ConsumerInterface;

class OrdersConsumer implements ConsumerInterface
{
    public function queue(): string
    {
        return 'orders';
    }

    public function handler()
    {
        return \\app\\queues\\RabbitMqHandler::class;
    }

    public function options(): array
    {
        return [
            'prefetch' => 1,
            'managedRetry' => true,
            'consumeFailFast' => true,
            'consumeMiddlewares' => [],
        ];
    }
}
```

ID берется из имени класса: `OrdersConsumer` -> `orders`, `AuditLogConsumer` -> `audit-log`.

## Console consume

CLI `rabbitmq/consume` запускает `ConsumeRunner` и использует ту же семантику пайплайна (middlewares, ExceptionClassifier, managed retry).

Discovery включается в конфиге компонента:

```php
'components' => [
    'rabbitmq' => [
        'class' => illusiard\\rabbitmq\\components\\RabbitMqService::class,
        'discovery' => [
            'enabled' => true,
            'paths' => ['@app/services/rabbitmq/consumers'],
            'cache' => 'cache',
            'cacheTtl' => 300,
        ],
    ],
],
```

Если discovery выключен, CLI запускает только по id и вернет ошибку без сканирования.

Ключевые опции CLI:
- `--managedRetry=1` и `--retryPolicy='{"maxAttempts":3,"retryQueues":[...],"deadQueue":"..."}'` (x-retry-count, retry/dead)
- `--consumeFailFast=0|1`, `--fatalExceptionClasses=...`, `--recoverableExceptionClasses=...`
- `--memoryLimitMb=...` (добавляет memory-limit middleware)
- `--readyLock=/path/to/lock` (опциональный lock-файл готовности)

Пример:

```bash
./yii rabbitmq/consume orders --memoryLimitMb=256 --managedRetry=1 \
  --retryPolicy='{"maxAttempts":3,"retryQueues":[{"name":"orders.retry.5s","ttlMs":5000}],"deadQueue":"orders.dead"}'
```

Список найденных consumers:

```bash
./yii rabbitmq/consume/consumers
```

## Multiple components / --component

Все console-команды поддерживают `--component=<id>` для выбора нужного компонента RabbitMqService.

```bash
./yii rabbitmq/healthcheck --component=rabbitmq2
./yii rabbitmq/consume orders --component=rabbitmq2 --readyLock=/tmp/consumer.lock
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

## Async return handler (mandatory-only)

При `mandatory=true` и `confirm=false` `basic.return` обрабатывается асинхронно.  
Чтобы обработчик получал события, вызовите `tick()` в цикле либо используйте внешний event loop.

Конфигурация:

```php
'components' => [
    'rabbitmq' => [
        'class' => illusiard\rabbitmq\components\RabbitMqService::class,
        'mandatory' => true,
        'returnHandler' => illusiard\rabbitmq\amqp\LoggingReturnHandler::class,
        'returnHandlerEnabled' => true,
    ],
],
```

Псевдо‑loop в publish‑only приложении:

```php
$rabbit = Yii::$app->get('rabbitmq');
$rabbit->publish('payload', 'exchange', 'rk');

// Дать возможность обработать basic.return (если есть).
$rabbit->tick(0.1);
```

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

JSON envelope формат: объект с `payload` и обязательным `messageId` (или `message_id`).

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

RPC и middleware:
- RPC Client/Server не используют общий publish/consume middleware pipeline.
- Это намеренно: RPC — отдельный протокольный слой с собственным циклом ожидания ответа.
- Расширение: можно кастомизировать handler (callable), сериализацию через Envelope/serializer, и таймауты в RpcClient::call().

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

## Integration tests

Запуск:

```bash
vendor/bin/phpunit -c phpunit.integration.xml
```

Env-флаги:
- `NACK_CAN_BE_FORCED` (confirm NACK path)
- `BLOCK_BROKER` (publishTimeout)
- `KILL_CONNECTION` (reconnect scenarios)
- `RABBIT_CAN_RESTART` (durable/persistent)
