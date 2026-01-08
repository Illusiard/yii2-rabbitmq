<?php

namespace illusiard\rabbitmq\tests\integration;

/**
 * @group integration
 */
class DurableIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_DURABLE_01_persistentAfterRestart(): void
    {
        if (!$this->envFlagEnabled('RABBIT_CAN_RESTART')) {
            $this->markTestSkipped('Set RABBIT_CAN_RESTART=1 and restart broker during the test.');
        }

        $queue = $this->uniqueName('durable_q');
        $this->declareQueue($queue, true);

        $this->publishRaw('payload', '', $queue, ['delivery_mode' => 2]);

        $wait = getenv('RABBIT_RESTART_WAIT_SEC');
        $waitSec = $wait !== false && is_numeric($wait) ? (int)$wait : 2;
        if ($waitSec > 0) {
            sleep($waitSec);
        }

        $count = $this->getQueueCount($queue);
        $this->assertGreaterThanOrEqual(1, $count);
    }
}
