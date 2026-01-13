<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

abstract class BaseRabbitMqController extends Controller
{
    public string $component = 'rabbitmq';

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['component']);
    }

    public function optionAliases(): array
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'component',
        ]);
    }

    protected function getRabbitService(): RabbitMqService
    {
        $service = Yii::$app->get($this->component);
        if (!$service instanceof RabbitMqService) {
            throw new InvalidArgumentException("Component '{$this->component}' must be an instance of RabbitMqService.");
        }

        return $service;
    }
}
