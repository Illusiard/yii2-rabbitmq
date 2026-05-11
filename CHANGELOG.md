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

### Fixed
- Семантика publish (confirm/mandatory) и обработка unroutable.
- Ошибка JSON decode больше не добавляет исходное тело сообщения в exception message.

### Removed
- Legacy consumer discovery API с `queue()/handler()/options()`.
- `onStart` callbacks из consume/RPC ready flow.
