<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

class TopologyStatusController extends Controller
{
    public string $component = 'rabbitmq';
    public bool $strict = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['component', 'strict']);
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
            $rabbit = $this->getRabbitService();
            $topology = $rabbit->buildTopology();
            if ($topology->isEmpty()) {
                $this->stderr("Topology config is empty.\n");
                return 1;
            }
            if ($this->strict) {
                $topology->validate();
            }

            $this->stdout("Exchanges:\n");
            foreach ($topology->getExchanges() as $exchange) {
                $this->stdout(' - ' . $exchange->getName() . ' (' . $exchange->getType() . ')' . PHP_EOL);
            }
            $this->stdout("Queues:\n");
            foreach ($topology->getQueues() as $queue) {
                $this->stdout(' - ' . $queue->getName() . PHP_EOL);
            }
            $this->stdout("Bindings:\n");
            foreach ($topology->getBindings() as $binding) {
                $this->stdout(' - ' . $binding->getQueue() . ' <- ' . $binding->getExchange() . ' [' . $binding->getRoutingKey() . ']' . PHP_EOL);
            }
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
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
