<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\components\RabbitMqService;
use InvalidArgumentException;

class DlqPurgeController extends Controller
{
    public string $component = 'rabbitmq';
    public int $force = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['component', 'force']);
    }

    public function optionAliases()
    {
        return array_merge(parent::optionAliases(), [
            'c' => 'component',
        ]);
    }

    public function actionIndex(string $queue): int
    {
        if ($this->force !== 1) {
            $this->stderr("Use --force=1 to purge.\n");
            return 1;
        }

        try {
            $service = new DlqService($this->getRabbitService());
            $service->purge($queue);
            $this->stdout("Purged.\n");
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
