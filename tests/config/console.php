<?php

return [
    'id' => 'yii2-rabbitmq-tests',
    'basePath' => dirname(__DIR__),
    'components' => [
        'rabbitmq' => [
            'class' => illusiard\rabbitmq\components\RabbitMqService::class,
        ],
    ],
];
