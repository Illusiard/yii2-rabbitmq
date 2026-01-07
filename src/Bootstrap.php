<?php

namespace illusiard\rabbitmq;

use yii\base\BootstrapInterface;
use illusiard\rabbitmq\components\RabbitMqService;

class Bootstrap implements BootstrapInterface
{
    public function bootstrap($app)
    {
        if (!$app->has('rabbitmq')) {
            $app->set('rabbitmq', [
                'class' => RabbitMqService::class,
            ]);
        }
    }
}
