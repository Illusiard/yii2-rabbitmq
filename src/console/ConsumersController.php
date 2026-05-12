<?php

namespace illusiard\rabbitmq\console;

use Throwable;

class ConsumersController extends BaseRabbitMqController
{
    public function actionIndex(): int
    {
        try {
            $registry = $this->getRabbitService()->getConsumerRegistry();
        } catch (Throwable $e) {
            $message = $this->isDiscoveryUnavailable($e)
                ? 'Discovery is disabled; enable it to list consumers.'
                : $e->getMessage();
            $this->stderr($message . PHP_EOL);
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

}
