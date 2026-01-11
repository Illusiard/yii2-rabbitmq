<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;
use illusiard\rabbitmq\dlq\DlqService;
use PhpAmqpLib\Wire\AMQPTable;

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

            $service = Yii::createObject(DlqService::class, [Yii::$app->get('rabbitmq')]);
            $items = $service->inspect($queue, $this->limit, (bool)$this->ack);

            if ($this->json) {
                $items = $this->normalizeItemsForJson($items);
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

    private function normalizeItemsForJson(array $items): array
    {
        foreach ($items as $index => $item) {
            $items[$index] = $this->normalizeValue($item);
        }

        return $items;
    }

    private function normalizeValue($value)
    {
        if (is_resource($value)) {
            return null;
        }

        if ($value instanceof AMQPTable) {
            return $value->getNativeData();
        }

        if (is_object($value)) {
            if ($value instanceof \JsonSerializable) {
                return $this->normalizeValue($value->jsonSerialize());
            }
            if (method_exists($value, '__toString')) {
                return (string)$value;
            }

            return ['__class' => get_class($value)];
        }

        if (is_array($value)) {
            foreach ($value as $key => $item) {
                $value[$key] = $this->normalizeValue($item);
            }
        }

        return $value;
    }
}
