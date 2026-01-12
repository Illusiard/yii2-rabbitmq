<?php

namespace illusiard\rabbitmq;

use Yii;
use yii\base\Module as BaseModule;
use illusiard\rabbitmq\components\RabbitMqService;
use yii\console\Application;

class Module extends BaseModule
{
    public function init()
    {
        parent::init();

        if (Yii::$app instanceof Application) {
            $this->controllerNamespace = 'illusiard\\rabbitmq\\console';
        }

        if (Yii::$app !== null && !Yii::$app->has('rabbitmq')) {
            Yii::$app->set('rabbitmq', [
                'class' => RabbitMqService::class,
            ]);
        }
    }
}
