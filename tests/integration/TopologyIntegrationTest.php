<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\amqp\TopologyManager;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;

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

        $manager = new TopologyManager($this->service->getConnection(), ['dryRun' => true]);
        $config = [
            'options' => [
                'dryRun' => true,
            ],
            'main' => [
                [
                    'queue' => $queue,
                    'exchange' => $exchange,
                    'routingKey' => $routingKey,
                ],
            ],
        ];

        $manager->apply($config, ['dryRun' => true]);

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

    public function testAMQP_TOPOLOGY_02_strictValidation(): void
    {
        $manager = new TopologyManager($this->service->getConnection(), ['strict' => true]);

        $config = [
            'main' => [
                [
                    'queue' => '',
                    'exchange' => 'ex',
                    'routingKey' => 'rk',
                ],
            ],
        ];

        try {
            $manager->apply($config, ['strict' => true]);
            $this->fail('Expected topology validation error.');
        } catch (RabbitMqException $e) {
            $this->assertSame(ErrorCode::TOPOLOGY_INVALID, $e->getErrorCode());
        }
    }
}
