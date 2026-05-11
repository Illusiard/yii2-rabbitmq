# illusiard/yii2-rabbitmq

Расширение Yii2 RabbitMQ с отложенным соединением, подтверждением публикации и управляемыми handlers c ретраем.

## Quickstart

Установка:

```bash
composer require illusiard/yii2-rabbitmq
```

Минимальная конфигурация компонента:

```php
return [
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\rabbitmq\components\RabbitMqService::class,
            'host' => '127.0.0.1',
            'port' => 5672,
            'user' => 'guest',
            'password' => 'guest',
        ],
    ],
];
```

Discovery для CLI:

```php
'components' => [
    'rabbitmq' => [
        'class' => illusiard\\rabbitmq\\components\\RabbitMqService::class,
        'discovery' => [
            'enabled' => true,
            'paths' => [
                '@app/services/rabbitmq/consumers',
                '@app/services/rabbitmq/publishers',
                '@app/services/rabbitmq/middlewares',
                '@app/services/rabbitmq/handlers', // опционально
            ],
            'cache' => 'cache',
            'cacheTtl' => 300,
        ],
    ],
],
```

Структура папок:

```
services/rabbitmq/consumers/OrdersConsumer.php
services/rabbitmq/publishers/OrdersPublisher.php
services/rabbitmq/middlewares/TraceIdMiddleware.php
services/rabbitmq/handlers/OrdersHandler.php
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
  - `x-retry-count` увеличивается при republish и ограничивается `maxAttempts`.
  - `retryExchange` на runtime берётся из `topology.options.retryExchange`; значение из retry policy используется только как fallback.
  - Сообщение уходит в dead при `dead` решении, иначе `reject` приводит к `basic_reject(false)`.
- DLQ inspect semantics:
  - `dlq-inspect` по умолчанию НЕ удаляет сообщения (`basic.get` + `nack requeue=true`).
  - Разрушающий режим только с `--ack=1 --force=1`.
- Consumer fail-fast semantics:
  - Fatal исключения приводят к `ConsumeResult::stop()` (если `consumeFailFast=true`).
  - Recoverable исключения приводят к `ConsumeResult::retry()` и включают retry/dead правила.

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

## Profile (project defaults)

Profile позволяет задать общие defaults для definitions (consumers/publishers) без копипаста опций.  
Это отдельная сущность от connection profiles и не обязательна.

Подключение профиля:

```php
'components' => [
    'rabbitmq' => [
        'class' => illusiard\\rabbitmq\\components\\RabbitMqService::class,
        'profile' => app\\services\\rabbitmq\\RabbitMqProfile::class,
    ],
],
```

Допустимые значения `profile`: `null`, FQCN, callable или объект.  
Если задан FQCN, используется `Yii::createObject()`.

Пример профиля:

```php
<?php

namespace app\\services\\rabbitmq;

use illusiard\\rabbitmq\\profile\\OptionsMerger;
use illusiard\\rabbitmq\\profile\\RabbitMqProfileInterface;

class RabbitMqProfile implements RabbitMqProfileInterface
{
    public function getConsumerDefaults(): array
    {
        return [
            'prefetch' => 10,
            'managedRetry' => true,
        ];
    }

    public function getPublisherDefaults(): array
    {
        return [
            'confirm' => true,
            'properties' => [
                'delivery_mode' => 2,
            ],
        ];
    }

    public function getMiddlewareDefaults(): array
    {
        return [
            'consumer' => ['trace', 'metrics'],
            'publisher' => ['trace'],
        ];
    }

    public function mergeOptions(array $defaults, array $overrides): array
    {
        return OptionsMerger::merge($defaults, $overrides);
    }
}
```

Правила merge:
- scalars: override
- associative arrays: recursive merge (overrides win)
- lists (numeric arrays): concat с уникальностью, defaults -> overrides
- если overrides содержит key => null, то дефолтный ключ удаляется

## Consumer definition

Consumer class (definitions):

```php
<?php

namespace app\\services\\rabbitmq\\consumers;

use illusiard\\rabbitmq\\definitions\\consumer\\ConsumerInterface;

class OrdersConsumer implements ConsumerInterface
{
    public function getQueue(): string
    {
        return 'orders';
    }

    public function getHandler()
    {
        return \\app\\queues\\RabbitMqHandler::class;
    }

    public function getOptions(): array
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

Component-level consume config (`managedRetry`, `retryPolicy`, `consumeMiddlewares`, `consumeFailFast`,
`fatalExceptionClasses`, `recoverableExceptionClasses`) является публичным runtime API. Эти значения
сливаются поверх profile defaults и consumer options, поэтому применяются и в CLI, и при прямом запуске
`ConsumeRunner`.

## CLI

Команды:

```bash
./yii rabbitmq/consume <consumer-id>
./yii rabbitmq/consumers
./yii rabbitmq/publishers
./yii rabbitmq/middlewares
./yii rabbitmq/topology-apply
./yii rabbitmq/topology-status
```

CLI `rabbitmq/consume` запускает `ConsumeRunner` и использует ту же семантику пайплайна (middlewares, ExceptionClassifier, managed retry).  
Запуск идёт по `consumer-id`, полученному из discovery. Если discovery выключен или paths не заданы, CLI вернет ошибку.

Ключевые опции `rabbitmq/consume`:
- `--managedRetry=1` и `--retryPolicy='{"maxAttempts":3,"retryExchange":"retry-exchange","retryQueues":[...],"deadQueue":"..."}'` (x-retry-count, retry/dead)
- `--consumeFailFast=0|1`, `--fatalExceptionClasses=...`, `--recoverableExceptionClasses=...`
- `--memoryLimitMb=...` (добавляет memory-limit middleware)
- `--readyLock=/path/to/lock` (lock-файл готовности)

Пример:

```bash
./yii rabbitmq/consume orders --memoryLimitMb=256 --managedRetry=1 \
  --retryPolicy='{"maxAttempts":3,"retryExchange":"retry-exchange","retryQueues":[{"name":"orders.retry.5s","ttlMs":5000}],"deadQueue":"orders.dead"}'
```

Список найденных consumers:

```bash
./yii rabbitmq/consumers
```

Список publishers/middlewares:

```bash
./yii rabbitmq/publishers
./yii rabbitmq/middlewares
```

## Multiple components / --component

Все console-команды поддерживают `--component=<id>` для выбора нужного компонента RabbitMqService.

```bash
./yii rabbitmq/healthcheck --component=rabbitmq2
./yii rabbitmq/consume orders --component=rabbitmq2 --readyLock=/tmp/consumer.lock
```

## Topology

Topology описывает exchanges/queues/bindings и их аргументы. Это не publish‑опции и не retry‑политика.
Если включён discovery, topology может дополняться из definitions через `getTopology()` или `getOptions()['topology']` (опционально).

```php
return [
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\\rabbitmq\\components\\RabbitMqService::class,
            'topology' => [
                'exchanges' => [
                    [
                        'name' => 'orders-exchange',
                        'type' => 'direct',
                        'durable' => true,
                    ],
                ],
                'queues' => [
                    [
                        'name' => 'orders',
                        'durable' => true,
                        'arguments' => [
                            'x-dead-letter-exchange' => 'orders-retry-exchange',
                            'x-dead-letter-routing-key' => 'orders.retry',
                        ],
                    ],
                    [
                        'name' => 'orders.retry',
                        'durable' => true,
                        'arguments' => [
                            'x-message-ttl' => 60000,
                            'x-dead-letter-exchange' => 'orders-exchange',
                            'x-dead-letter-routing-key' => 'orders',
                        ],
                    ],
                ],
                'bindings' => [
                    [
                        'exchange' => 'orders-exchange',
                        'queue' => 'orders',
                        'routingKey' => 'orders',
                    ],
                ],
            ],
        ],
    ],
];
```

CLI:

```bash
./yii rabbitmq/topology-apply
./yii rabbitmq/topology-apply --dryRun=1 --strict=1
./yii rabbitmq/topology-status
```

## Consume semantics

- Handler может вернуть `ConsumeResult` или legacy `bool`.
  - `true` → ACK
  - `false` → RETRY (дальше применяется retry policy)
- Exception classification:
  - `consumeFailFast=true` превращает fatal исключения в `ConsumeResult::stop()` и завершает consumer.
  - Recoverable исключения приводят к `ConsumeResult::retry()` и проходят через retry policy.
- Managed retry публикует retry-сообщения через `topology.options.retryExchange`, использует `x-retry-count` и не опирается на `x-death`.

## Publish semantics

- `confirm=true` включает publisher confirms: публикация ждёт ack/nack, при nack или таймауте будет `PublishException`.
- `mandatory=true` включает mandatory publish: unroutable сообщения фиксируются через ReturnSink, без блокирующего ожидания.
- `mandatoryStrict=true` (по умолчанию) делает unroutable ошибкой при `confirm=true` — будет `PublishException(PUBLISH_UNROUTABLE)`.
- `publishTimeout` задаёт таймаут ожидания подтверждения при `confirm=true`.

Возвраты обрабатываются через ReturnSink и доступны через `tick()` + `drainReturns()`.  
В режиме `mandatory=true` без confirms это best‑effort: ошибки доставки нужно опрашивать.

## ReturnSink и tick/poll

При `mandatory=true` callbacks `basic.return` снимаются при вызове `tick()` и попадают в ReturnSink.

Конфигурация:

```php
'components' => [
    'rabbitmq' => [
        'class' => illusiard\rabbitmq\components\RabbitMqService::class,
        'mandatory' => true,
        'returnSink' => illusiard\rabbitmq\amqp\InMemoryReturnSink::class,
        'returnSinkEnabled' => true,
    ],
],
```

Псевдо‑loop в publish‑only приложении:

```php
$rabbit = Yii::$app->get('rabbitmq');
$rabbit->publish('payload', 'exchange', 'rk');

// Снять события возвратов и ACK/NACK (если включён confirm).
$rabbit->tick(0.1);
$returns = $rabbit->drainReturns();
```

## Managed retry

Если `managedRetry=true`, то при `handler=false` или `ConsumeResult::retry()` применяется retry policy и:

- retry — перепубликация в retry-очередь через `topology.options.retryExchange` или fallback `retryPolicy.retryExchange`, затем ACK
- dead — перепубликация в dead-очередь (если указана), затем ACK
- reject — `basic_reject(false)`

`maxAttempts` обязателен и должен быть положительным integer. Каждая `retryQueues[]` запись требует
`name` и `ttlMs`. При исчерпании попыток `x-retry-count` записывается как
`min(currentAttempt + 1, maxAttempts)`.

Пример политики:

```php
'managedRetry' => true,
'retryPolicy' => [
    'maxAttempts' => 3,
    'retryExchange' => 'retry-exchange',
    'retryQueues' => [
        ['name' => 'orders.retry.5s', 'ttlMs' => 5000],
        ['name' => 'orders.retry.30s', 'ttlMs' => 30000],
    ],
    'deadQueue' => 'orders.dead',
],
```

Если в topology задан `options.retryExchange`, runtime retry всегда публикует туда, даже если
`retryPolicy.retryExchange` отличается.

## Ready protocol

CLI consumer readiness сигнализируется lock-файлом (stdout не используется).  
`--readyLock=/path/to/lock` создаёт lock-файл при старте и удаляет при остановке.

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
- выполните `rabbitmq/topology-apply` или проверьте provisioning инфраструктуры

Permissions/vhost errors:
- проверьте `vhost`, права пользователя и учётные данные

Connection refused / heartbeat timeouts:
- проверьте доступность хоста/порта, firewall, а также `heartbeat` и таймауты

Consumer exits on exception:
- fatal исключения завершают процесс (fail-fast), recoverable возвращают `ConsumeResult::retry()` и запускают retry/dead
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

Локальный RabbitMQ (docker compose):

```bash
docker compose up -d rabbitmq
```

```yaml
## docker-compose.yml
services:
  rabbitmq:
    image: rabbitmq:3.13-management
    ports:
      - "5672:5672"
      - "15672:15672"
```

Env для подключения:
- `RABBIT_HOST` (default: localhost)
- `RABBIT_PORT` (default: 5672)
- `RABBIT_USER` (default: guest)
- `RABBIT_PASSWORD` (default: guest)
- `RABBIT_VHOST` (default: /)

Env-флаги:
- `NACK_CAN_BE_FORCED` (confirm NACK path)
- `BLOCK_BROKER` (publishTimeout)
- `KILL_CONNECTION` (reconnect scenarios)
- `RABBIT_CAN_RESTART` (durable/persistent)
