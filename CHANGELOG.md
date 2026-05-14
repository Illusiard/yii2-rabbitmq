# Changelog

## Unreleased

### Added
- ConsumeRunner с lock-файлом и плавными перехватчиками завершения работы.
- Опция `--component` для console-команд.
- Definitions слой с интерфейсами/абстракциями и get*-геттерами; введены ConsumeContext/ConsumeResult.
- Discovery/registries для definitions, CLI consume по id и команды списка consumers/publishers/middlewares.
- TopologyBuilder/TopologyApplier и CLI команды topology-apply/status.
- ReturnSink и API tick/drainReturns; корреляция confirm по sequence+message_id.
- Опциональный RabbitMqProfileInterface и merge defaults профиля для definitions.
- Production `ProcessHelper` рядом с `FileHelper`.
- Публичный `RabbitMqService::publishById()` для публикации через publisher definitions.
- Настройки `consumeReconnectAttempts`/`consumeReconnectDelaySeconds`.
- Настройки `maxMessageBodyBytes`, `jsonDecodeDepth`, `messageLimitExceededAction`.
- Redaction helper для diagnostics, CLI stderr и publish/connection/RPC ошибок.
- JSON output для `rabbitmq/consumers`, `rabbitmq/publishers`, `rabbitmq/topology-status`.
- Packagist metadata (`homepage`, `support`, `authors`).

### Changed
- Нормализован consume pipeline (ConsumeResult/ExceptionClassifier/RetryPolicy); retry вынесен из AmqpConsumer.
- Интеграционные тесты используют lock-файл готовности вместо stdout ready.
- ConsumeRunner по умолчанию использует `runtime/rabbitmq/<consumer-id>.lock`.
- Topology строится только из topology-конфига сервиса, без consumers/publishers definitions.
- Envelope detection в JSON serializer переведён на структурную проверку JSON.
- HandlerInterface явно принимает `Envelope` и возвращает `ConsumeResult|bool`.
- PublisherInterface требует `getRoutingKey()`.
- Порядок middleware зафиксирован как `system-before -> user -> system-after`.
- JSON decode больше не делает fallback invalid JSON в plain payload; content type фиксирован как `application/json`, `message_id` из AMQP может стать `Envelope::messageId`.
- Строковый handler теперь обязан разрешаться в `HandlerInterface`.
- `x-retry-count` sanitizes/clamps перед retry decision и перезаписывается при republish.
- Managed retry требует `maxAttempts`; exhausted retry приводит к DLQ/reject/stop согласно политике, `x-death` остаётся только диагностикой.
- Topology apply должен быть идемпотентным для совпадающих объявлений; consumer queues валидируются при сборке topology.
- Topology CLI по умолчанию работает в dry-run режиме, реальное применение требует явного `--dryRun=0`.
- Стандартные consume/error logs больше не включают payload/body, headers/properties или exception message.
- `TopologyBuilder` использует общий `ArrayHelper::normalizeItems()`.
- `JsonMessageSerializer` получил настраиваемый лимит глубины decode.
- Consumer reconnect policy больше не зашит в фиксированные 3 попытки с `sleep(1)`.
- `publishJson()` учитывает `messageId` из options.

### Fixed
- Семантика publish (confirm/mandatory) и обработка unroutable.
- Ошибка JSON decode больше не добавляет исходное тело сообщения в exception message.
- Recoverable exceptions больше не обходят managed retry: `ExceptionHandlingMiddleware` передаёт retry result в `RetryPolicyMiddleware`.
- Component-level consume config (`managedRetry`, `retryPolicy`, `consumeMiddlewares`, `consumeFailFast`, exception class lists) применяется в consumer flow.
- Runtime retryExchange синхронизирован с topology: `topology.options.retryExchange` является источником истины.
- `x-retry-count` при exhausted retry больше не превышает `maxAttempts`.
- RetryPolicy теперь строго валидирует `maxAttempts` и `retryQueues[]` до запуска consumer.
- Discovery корректно работает с multi-path конфигурацией для consumers/publishers/middlewares/handlers.
- Topology dry-run валидирует topology offline, без подключения к брокеру.
- Component-level consume middleware больше не дублируются в pipeline.
- `setupTopology()` валидирует очереди discovered consumers.
- `JsonMessageSerializer` всегда заворачивает ошибки JSON encode/decode в `RabbitMqException`.
- README topology notes обновлены под текущую dry-run/strict семантику.
- `ping()->getLastError()` и CLI errors больше не раскрывают password/token/userinfo DSN.
- `FileHelper::atomicWrite()` отказывается перезаписывать symlink target, а `ensureDir()` отказывается от symlink directory.
- Oversized incoming messages классифицируются до handler-а и не включают body в exception message.
- Yii2 serializer config теперь корректно передает `jsonDecodeDepth` в стандартный JSON serializer.

### Removed
- Legacy consumer discovery API с `queue()/handler()/options()`.
- `onStart` callbacks из consume/RPC ready flow.
- Дублирующий `SetupTopologyController`; остается `TopologyApplyController`.
- Пустой `AbstractHandler`; контракт задает `HandlerInterface`.
- Опция `throwOnError` у `JsonMessageSerializer`; ошибки сериализации всегда package-level.
