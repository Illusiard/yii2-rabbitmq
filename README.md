# illusiard/yii2-rabbitmq

Minimal Yii2 RabbitMQ extension skeleton with lazy connection and stub interfaces.
MQTT is intentionally not implemented here.

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
        ],
    ],
];
```

## Usage (stubbed)

```php
/** @var illusiard\rabbitmq\components\RabbitMqService $rabbit */
$rabbit = Yii::$app->get('rabbitmq');

$rabbit->publish('exchange', 'route.key', 'Hello');

$rabbit->consume('queue.name', function ($message) {
    // Handle message (stub)
});
```

These methods are stubs until a real AMQP library is wired in.
