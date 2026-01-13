<?php

namespace illusiard\rabbitmq\topology;

use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Wire\AMQPTable;
use Yii;

class TopologyApplier
{
    public function apply(Topology $topology, AMQPChannel $channel, bool $dryRun = false): array
    {
        $steps = [];

        foreach ($topology->getExchanges() as $exchange) {
            $steps[] = [
                'action' => 'exchange_declare',
                'name' => $exchange->getName(),
                'type' => $exchange->getType(),
            ];

            if (!$dryRun) {
                $channel->exchange_declare(
                    $exchange->getName(),
                    $exchange->getType(),
                    false,
                    $exchange->isDurable(),
                    $exchange->isAutoDelete(),
                    $exchange->isInternal(),
                    false,
                    $exchange->getArguments()
                );
            }
        }

        foreach ($topology->getQueues() as $queue) {
            $steps[] = [
                'action' => 'queue_declare',
                'name' => $queue->getName(),
            ];

            if (!$dryRun) {
                $args = $queue->getArguments();
                $channel->queue_declare(
                    $queue->getName(),
                    false,
                    $queue->isDurable(),
                    $queue->isExclusive(),
                    $queue->isAutoDelete(),
                    false,
                    new AMQPTable($args)
                );
            }
        }

        foreach ($topology->getBindings() as $binding) {
            $steps[] = [
                'action' => 'queue_bind',
                'queue' => $binding->getQueue(),
                'exchange' => $binding->getExchange(),
            ];

            if (!$dryRun) {
                $args = $binding->getArguments();
                $channel->queue_bind(
                    $binding->getQueue(),
                    $binding->getExchange(),
                    $binding->getRoutingKey(),
                    false,
                    new AMQPTable($args)
                );
            }
        }

        if ($dryRun) {
            foreach ($steps as $step) {
                Yii::info('Topology plan: ' . json_encode($step), 'rabbitmq');
            }
        }

        return $steps;
    }
}
