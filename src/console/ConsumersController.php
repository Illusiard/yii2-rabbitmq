<?php

namespace illusiard\rabbitmq\console;

use JsonException;
use Throwable;

class ConsumersController extends BaseRabbitMqController
{
    public int $json = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['json']);
    }

    /**
     * @return int
     * @throws JsonException
     */
    public function actionIndex(): int
    {
        try {
            $registry = $this->getRabbitService()->getConsumerRegistry();
        } catch (Throwable $e) {
            $message = $this->isDiscoveryUnavailable($e)
                ? 'Discovery is disabled; enable it to list consumers.'
                : $this->exceptionMessage($e);
            $this->stderr($message . PHP_EOL);
            return 1;
        }

        $items = $registry->all();
        ksort($items);

        if ($this->json) {
            $this->stdout(json_encode($items, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
            return 0;
        }

        if (empty($items)) {
            $this->stdout("No consumers found.\n");
            return 0;
        }

        foreach ($items as $id => $fqcn) {
            $this->stdout($id . "\t" . $fqcn . PHP_EOL);
        }

        return 0;
    }

}
