<?php

namespace illusiard\rabbitmq\console;

use Throwable;

class PublishersController extends BaseRabbitMqController
{
    public function actionIndex(): int
    {
        try {
            $registry = $this->getRabbitService()->getPublisherRegistry();
        } catch (Throwable $e) {
            $message = $this->isDiscoveryUnavailable($e)
                ? 'Discovery is disabled; enable it to list publishers.'
                : $e->getMessage();
            $this->stderr($message . PHP_EOL);
            return 1;
        }

        $items = $registry->all();
        if (empty($items)) {
            $this->stdout("No publishers found.\n");
            return 0;
        }

        ksort($items);
        foreach ($items as $id => $fqcn) {
            $this->stdout($id . "\t" . $fqcn . PHP_EOL);
        }

        return 0;
    }

}
