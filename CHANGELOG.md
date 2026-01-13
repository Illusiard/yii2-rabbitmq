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

### Changed
- Нормализован consume pipeline (ConsumeResult/ExceptionClassifier/RetryPolicy); retry вынесен из AmqpConsumer.
- Интеграционные тесты используют lock-файл готовности вместо stdout ready.

### Fixed
- Семантика publish (confirm/mandatory) и обработка unroutable.
