# Integration Tests

## How to run

```bash
vendor/bin/phpunit -c phpunit.integration.xml
```

RabbitMQ defaults:
- host: `localhost`
- port: `5672`
- user: `guest`
- password: `guest`
- vhost: `/`

You can override via env:
- `RABBIT_HOST`
- `RABBIT_PORT`
- `RABBIT_USER`
- `RABBIT_PASSWORD`
- `RABBIT_VHOST`

## Conditional scenarios (env flags)

Some tests are skipped unless the environment can deterministically provide conditions:
- `NACK_CAN_BE_FORCED=1` (confirm NACK path)
- `BLOCK_BROKER=1` (publishTimeout scenario)
- `KILL_CONNECTION=1` (reconnect scenarios)
- `RABBIT_CAN_RESTART=1` (durable/persistent scenario)

The smoke test `RabbitAvailabilityTest` fails if RabbitMQ is unreachable, instead of skipping.
