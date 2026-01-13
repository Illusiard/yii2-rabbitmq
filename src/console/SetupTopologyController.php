<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

class SetupTopologyController extends Controller
{
    public string $component = 'rabbitmq';
    public bool $dryRun = false;
    public bool $strict = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['component', 'dryRun', 'strict']);
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
            $topology = $rabbit->topology ?? [];

            if (empty($topology)) {
                $this->stderr("Topology config is empty.\n");
                return 1;
            }

            $options = $topology['options'] ?? [];
            $options['dryRun'] = $this->dryRun;
            $options['strict'] = $this->strict;
            $topology['options'] = $options;

            $rabbit->setupTopology($topology);
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
