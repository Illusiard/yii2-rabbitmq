<?php

namespace illusiard\rabbitmq\console;

class MiddlewaresController extends BaseRabbitMqController
{
    public function actionIndex(): int
    {
        try {
            $registry = $this->getRabbitService()->getMiddlewareRegistry();
        } catch (\Throwable $e) {
            $this->stderr("Discovery is disabled; enable it to list middlewares.\n");
            return 1;
        }

        $items = $registry->all();
        if (empty($items)) {
            $this->stdout("No middlewares found.\n");
            return 0;
        }

        ksort($items);
        foreach ($items as $id => $fqcn) {
            $this->stdout($id . "\t" . $fqcn . PHP_EOL);
        }

        return 0;
    }

}
