<?php

namespace illusiard\rabbitmq;

use yii\base\BootstrapInterface;
use illusiard\rabbitmq\components\RabbitMqService;
use yii\base\InvalidConfigException;
use yii\console\Application as ConsoleApplication;

class Bootstrap implements BootstrapInterface
{
    /**
     * @param $app
     * @return void
     * @throws InvalidConfigException
     */
    public function bootstrap($app): void
    {
        if (!$app->has('rabbitmq')) {
            $app->set('rabbitmq', [
                'class' => RabbitMqService::class,
            ]);
        }

        if ($app instanceof ConsoleApplication && !$app->hasModule('rabbitmq')) {
            $app->setModule('rabbitmq', [
                'class' => Module::class,
            ]);
        }
    }
}
