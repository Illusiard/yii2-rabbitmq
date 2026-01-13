<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use illusiard\rabbitmq\topology\BindingDefinition;

/**
 * @group integration
 */
class TopologyIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_TOPOLOGY_01_dryRun(): void
    {
        $exchange = $this->uniqueName('dry_ex');
        $queue = $this->uniqueName('dry_q');
        $routingKey = 'rk';

        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition($exchange, 'direct'));
        $topology->addQueue(new QueueDefinition($queue));
        $topology->addBinding(new BindingDefinition($exchange, $queue, $routingKey));

        $this->service->applyTopology($topology, true);

        $channel = $this->getChannel();
        $created = true;
        try {
            $channel->queue_declare($queue, true);
        } catch (\Throwable $e) {
            $created = false;
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }

        $this->assertFalse($created, 'Dry-run should not create queues.');
    }

    public function testAMQP_TOPOLOGY_02_applyCreatesTopology(): void
    {
        $exchange = $this->uniqueName('ex');
        $queue = $this->uniqueName('q');
        $routingKey = 'rk';

        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition($exchange, 'direct'));
        $topology->addQueue(new QueueDefinition($queue));
        $topology->addBinding(new BindingDefinition($exchange, $queue, $routingKey));

        $this->service->applyTopology($topology, false);

        $this->publishRaw('demo', $exchange, $routingKey);
        $message = $this->waitForMessage($queue, 3);
        $this->assertNotNull($message);
    }
}
