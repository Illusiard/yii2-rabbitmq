<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

class ConsumersController extends Controller
{
    public string $component = 'rabbitmq';

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['component']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'component',
        ]);
    }

    public function actionIndex(): int
    {
        try {
            $registry = $this->getRabbitService()->getConsumerRegistry();
        } catch (\Throwable $e) {
            $this->stderr("Discovery is disabled; enable it to list consumers.\n");
            return 1;
        }

        $items = $registry->all();
        if (empty($items)) {
            $this->stdout("No consumers found.\n");
            return 0;
        }

        ksort($items);
        foreach ($items as $id => $fqcn) {
            $this->stdout($id . "\t" . $fqcn . PHP_EOL);
        }

        return 0;
    }

    private function getRabbitService(): RabbitMqService
    {
        $service = Yii::$app->get($this->component);
        if (!$service instanceof RabbitMqService) {
            throw new InvalidArgumentException("Component '{$this->component}' must be an instance of RabbitMqService.");
        }

        return $service;
    }
}
