<?php

namespace illusiard\rabbitmq\console;

use Yii;
use yii\console\Controller;

class SetupTopologyController extends Controller
{
    public bool $dryRun = false;
    public bool $strict = false;

    public function options($actionID)
    {
        return array_merge(parent::options($actionID), ['dryRun', 'strict']);
    }

    public function actionIndex(): int
    {
        try {
            $rabbit = Yii::$app->get('rabbitmq');
            $topology = $rabbit->topology ?? [];

            if (empty($topology)) {
                $this->stderr("Topology config is empty.\n");
                return 1;
            }

            $options = $topology['options'] ?? [];
            $options['dryRun'] = $this->dryRun;
            $options['strict'] = $this->strict;
            $topology['options'] = $options;

            $rabbit->setupTopology($topology);
        } catch (\Throwable $e) {
            $this->stderr($e->getMessage() . PHP_EOL);
            return 1;
        }

        return 0;
    }
}
