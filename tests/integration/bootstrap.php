<?php

require __DIR__ . '/../../vendor/autoload.php';

define('YII_ENV', 'test');
define('YII_DEBUG', true);

require __DIR__ . '/../../vendor/yiisoft/yii2/Yii.php';

$config = [
    'id' => 'rabbitmq-integration-tests',
    'basePath' => dirname(__DIR__, 2),
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\rabbitmq\components\RabbitMqService::class,
            'host' => getenv('RABBIT_HOST') ?: 'localhost',
            'port' => getenv('RABBIT_PORT') ? (int)getenv('RABBIT_PORT') : 5672,
            'user' => getenv('RABBIT_USER') ?: 'guest',
            'password' => getenv('RABBIT_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBIT_VHOST') ?: '/',
            'heartbeat' => 30,
            'readWriteTimeout' => 3,
            'connectionTimeout' => 3,
            'confirm' => false,
            'mandatory' => false,
            'publishTimeout' => 5,
            'managedRetry' => false,
            'retryPolicy' => [],
            'publishMiddlewares' => [],
            'consumeMiddlewares' => [],
        ],
    ],
];

new yii\console\Application($config);
