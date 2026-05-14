<?php

namespace illusiard\rabbitmq\console;

use Throwable;

class TopologyStatusController extends BaseRabbitMqController
{
    public bool $strict = false;
    public int $json = 0;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['strict', 'json']);
    }

    /**
     * @return int
     */
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

            if ($this->json) {
                $this->stdout(json_encode([
                        'exchanges' => array_map(static fn($exchange): array => [
                            'name' => $exchange->getName(),
                            'type' => $exchange->getType(),
                            'durable' => $exchange->isDurable(),
                            'autoDelete' => $exchange->isAutoDelete(),
                            'internal' => $exchange->isInternal(),
                            'arguments' => $exchange->getArguments(),
                        ], $topology->getExchanges()),
                        'queues' => array_map(static fn($queue): array => [
                            'name' => $queue->getName(),
                            'durable' => $queue->isDurable(),
                            'autoDelete' => $queue->isAutoDelete(),
                            'exclusive' => $queue->isExclusive(),
                            'arguments' => $queue->getArguments(),
                        ], $topology->getQueues()),
                        'bindings' => array_map(static fn($binding): array => [
                            'exchange' => $binding->getExchange(),
                            'queue' => $binding->getQueue(),
                            'routingKey' => $binding->getRoutingKey(),
                            'arguments' => $binding->getArguments(),
                        ], $topology->getBindings()),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
                return 0;
            }

            $this->stdout("Exchanges:\n");
            foreach ($topology->getExchanges() as $exchange) {
                $this->stdout(' - ' . $exchange->getName() . ' (' . $exchange->getType() . ')' . PHP_EOL);
            }
            $this->stdout("Queues:\n");
            foreach ($topology->getQueues() as $queue) {
                $this->stdout(' - ' . $queue->getName() . PHP_EOL);
            }
            $this->stdout("Bindings:\n");
            foreach ($topology->getBindings() as $binding) {
                $this->stdout(' - ' . $binding->getQueue() . ' <- ' . $binding->getExchange() . ' [' . $binding->getRoutingKey() . ']' . PHP_EOL);
            }
        } catch (Throwable $e) {
            $this->stderr($this->exceptionMessage($e) . PHP_EOL);
            return 1;
        }

        return 0;
    }

}
