<?php

namespace illusiard\rabbitmq;

use Yii;
use yii\base\Module as BaseModule;
use illusiard\rabbitmq\components\RabbitMqService;

class Module extends BaseModule
{
    public function init()
    {
        parent::init();

        if (Yii::$app !== null && !Yii::$app->has('rabbitmq')) {
            Yii::$app->set('rabbitmq', [
                'class' => RabbitMqService::class,
            ]);
        }
    }
}
