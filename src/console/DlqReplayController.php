<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\dlq\DlqService;

class DlqReplayController extends Controller
{
    public string $exchange = '';
    public string $routingKey = '';
    public int $limit = 100;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['exchange', 'routingKey', 'limit']);
    }

    public function actionIndex(string $fromQueue): int
    {
        if ($this->exchange === '' || $this->routingKey === '') {
            $this->stderr("exchange and routingKey are required.\n");
            return 1;
        }

        try {
            $service = new DlqService(Yii::$app->get('rabbitmq'));
            $count = $service->replay($fromQueue, $this->exchange, $this->routingKey, $this->limit);
            $this->stdout("Replayed: " . $count . PHP_EOL);
            return 0;
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
