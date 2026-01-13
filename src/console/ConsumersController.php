<?php

namespace illusiard\rabbitmq\console;

class ConsumersController extends BaseRabbitMqController
{
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

}
