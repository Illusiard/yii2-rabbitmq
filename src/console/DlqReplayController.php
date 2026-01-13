<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

class DlqReplayController extends Controller
{
    public string $component = 'rabbitmq';
    public string $exchange = '';
    public string $routingKey = '';
    public int $limit = 100;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['component', 'exchange', 'routingKey', 'limit']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'component',
        ]);
    }

    public function actionIndex(string $fromQueue): int
    {
        if ($this->exchange === '' || $this->routingKey === '') {
            $this->stderr("exchange and routingKey are required.\n");
            return 1;
        }

        try {
            $service = new DlqService($this->getRabbitService());
            $count = $service->replay($fromQueue, $this->exchange, $this->routingKey, $this->limit);
            $this->stdout("Replayed: " . $count . PHP_EOL);
            return 0;
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }
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
