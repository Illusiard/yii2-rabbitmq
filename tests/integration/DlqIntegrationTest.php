<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\console\DlqInspectController;

/**
 * @group integration
 */
class DlqIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_DLQ_INSPECT_01_safeMode(): void
    {
        $queue = $this->uniqueName('dead');
        $this->declareQueue($queue);

        $this->publishRaw('one', '', $queue, ['message_id' => 'm1']);
        $this->publishRaw('two', '', $queue, ['message_id' => 'm2']);

        $before = $this->getQueueCount($queue);
        $service = new DlqService($this->service);
        $items = $service->inspect($queue, 2, false);

        $after = $this->getQueueCount($queue);
        $this->assertSame($before, $after);
        $this->assertGreaterThanOrEqual(1, count($items));
    }

    public function testAMQP_DLQ_INSPECT_02_destructiveModeRequiresForce(): void
    {
        $queue = $this->uniqueName('dead');
        $this->declareQueue($queue);

        $this->publishRaw('one', '', $queue, ['message_id' => 'm1']);

        $controller = new DlqInspectController('rabbitmq/dlq-inspect', \Yii::$app);
        $controller->limit = 1;
        $controller->ack = 1;
        $controller->force = 0;

        $code = $controller->actionIndex($queue);
        $this->assertSame(1, $code);

        $controller->force = 1;
        $code = $controller->actionIndex($queue);
        $this->assertSame(0, $code);

        $this->assertTrue($this->waitForQueueCount($queue, 0));
    }

    public function testAMQP_DLQ_REPLAY_01_metadataPreserved(): void
    {
        $fromQueue = $this->uniqueName('dead');
        $toQueue = $this->uniqueName('replay_target');
        $exchange = $this->uniqueName('replay_ex');
        $routingKey = 'rk';

        $this->declareQueue($fromQueue);
        $this->declareExchange($exchange);
        $this->declareQueue($toQueue);
        $this->bindQueue($toQueue, $exchange, $routingKey);

        $properties = [
            'message_id' => 'mid_' . uniqid('', true),
            'correlation_id' => 'cid_' . uniqid('', true),
        ];
        $headers = [
            'x-trace-id' => 'trace-1',
        ];

        $this->publishRaw('payload', '', $fromQueue, $properties, $headers);

        $service = new DlqService($this->service);
        $count = $service->replay($fromQueue, $exchange, $routingKey, 1);
        $this->assertSame(1, $count);

        $message = $this->waitForMessage($toQueue, 5);
        $this->assertNotNull($message);

        $msgProps = $message->get_properties();
        $this->assertSame($properties['message_id'], $msgProps['message_id'] ?? null);
        $this->assertSame($properties['correlation_id'], $msgProps['correlation_id'] ?? null);

        $msgHeaders = [];
        if (isset($msgProps['application_headers'])) {
            $msgHeaders = $msgProps['application_headers']->getNativeData();
        }
        $this->assertSame('trace-1', $msgHeaders['x-trace-id'] ?? null);
    }
}
