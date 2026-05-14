<?php

namespace illusiard\rabbitmq\console;

use illusiard\rabbitmq\dlq\DlqService;
use Throwable;

class DlqPurgeController extends BaseRabbitMqController
{
    public int $force = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['force']);
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
        } catch (Throwable $e) {
            $this->stderr($this->exceptionMessage($e) . PHP_EOL);
            return 1;
        }
    }

}
