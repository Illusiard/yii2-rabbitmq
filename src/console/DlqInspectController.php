<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\dlq\DlqService;

class DlqInspectController extends Controller
{
    public int $limit = 10;
    public int $json = 0;
    public int $ack = 0;
    public int $force = 0;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['limit', 'json', 'ack', 'force']);
    }

    public function actionIndex(string $queue): int
    {
        try {
            if ($this->ack && !$this->force) {
                $this->stderr("Refusing destructive inspect without --force=1.\n");
                return 1;
            }

            $service = new DlqService(Yii::$app->get('rabbitmq'));
            $items = $service->inspect($queue, $this->limit, (bool)$this->ack);

            if ($this->json) {
                $this->stdout(json_encode($items) . PHP_EOL);
            } else {
                $this->stdout(print_r($items, true) . PHP_EOL);
            }
            return 0;
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }
    }
}
