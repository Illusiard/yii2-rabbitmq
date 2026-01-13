<?php

namespace illusiard\rabbitmq\console;

class TopologyApplyController extends BaseRabbitMqController
{
    public bool $dryRun = false;
    public bool $strict = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['dryRun', 'strict']);
    }

    public function actionIndex(): int
    {
        try {
            $rabbit = $this->getRabbitService();
            $topology = $rabbit->buildTopology();
            if ($topology->isEmpty()) {
                $this->stderr("Topology config is empty.\n");
                return 1;
            }
            if ($this->strict) {
                $topology->validate();
            }
            $rabbit->applyTopology($topology, $this->dryRun);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }

        return 0;
    }

}
