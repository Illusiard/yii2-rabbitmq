<?php

namespace illusiard\rabbitmq\console;

use Throwable;
use Yii;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\helpers\SensitiveDataHelper;
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

    /**
     * @return RabbitMqService
     * @throws InvalidConfigException
     */
    protected function getRabbitService(): RabbitMqService
    {
        $service = Yii::$app->get($this->component);
        if (!$service instanceof RabbitMqService) {
            throw new InvalidArgumentException("Component '$this->component' must be an instance of RabbitMqService.");
        }

        return $service;
    }

    protected function isDiscoveryUnavailable(Throwable $e): bool
    {
        return in_array($e->getMessage(), [
            'Discovery is disabled.',
            'Discovery paths are not configured.',
            'Handler discovery is disabled because no handlers path is configured.',
        ], true);
    }

    protected function exceptionMessage(Throwable $e): string
    {
        return SensitiveDataHelper::redact($e->getMessage());
    }
}
