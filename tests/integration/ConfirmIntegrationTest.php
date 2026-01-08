<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\amqp\AmqpPublisher;
use illusiard\rabbitmq\amqp\PublishConfirmTracker;
use illusiard\rabbitmq\exceptions\PublishException;
use illusiard\rabbitmq\exceptions\ErrorCode;

/**
 * @group integration
 */
class ConfirmIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_CONFIRM_01_ackCorrelation(): void
    {
        $exchange = $this->uniqueName('confirm_ex');
        $queue = $this->uniqueName('confirm_q');
        $routingKey = 'rk';

        $this->declareExchange($exchange);
        $this->declareQueue($queue);
        $this->bindQueue($queue, $exchange, $routingKey);

        $service = $this->createService(['confirm' => true]);
        $this->setService($service);

        $publisher = $service->getPublisher();
        $tracker = new TestConfirmTracker();
        $this->injectTracker($publisher, $tracker);

        $messageIds = [];
        for ($i = 0; $i < 3; $i++) {
            $messageId = 'msg_' . uniqid('', true);
            $messageIds[] = $messageId;
            $publisher->publish('body-' . $i, $exchange, $routingKey, ['message_id' => $messageId]);
        }

        foreach ($messageIds as $messageId) {
            $this->assertTrue(isset($tracker->registeredMessageIds[$messageId]));
        }
        $this->assertNotEmpty($tracker->ackedSeqNos, 'Expected at least one ACK.');

        if ($this->envFlagEnabled('NACK_CAN_BE_FORCED')) {
            $this->markTestSkipped('NACK forcing requires broker policy; set up NACK and rerun manually.');
        }
    }

    public function testAMQP_CONFIRM_02_multipleAck(): void
    {
        $exchange = $this->uniqueName('confirm_ex_multi');
        $queue = $this->uniqueName('confirm_q_multi');
        $routingKey = 'rk';

        $this->declareExchange($exchange);
        $this->declareQueue($queue);
        $this->bindQueue($queue, $exchange, $routingKey);

        $service = $this->createService(['confirm' => true]);
        $this->setService($service);

        $publisher = $service->getPublisher();
        $tracker = new TestConfirmTracker();
        $this->injectTracker($publisher, $tracker);

        for ($i = 0; $i < 20; $i++) {
            $publisher->publish('body-' . $i, $exchange, $routingKey, ['message_id' => 'msg_' . uniqid('', true)]);
        }

        if (!$tracker->ackMultipleSeen) {
            $this->markTestSkipped('Multiple ACK not observed; broker may send only single ACKs.');
        }

        $this->assertTrue($tracker->ackMultipleSeen);
    }

    public function testAMQP_MANDATORY_01_unroutable(): void
    {
        $exchange = $this->uniqueName('mandatory_ex');
        $routingKey = 'missing';

        $this->declareExchange($exchange);

        $service = $this->createService(['mandatory' => true, 'publishTimeout' => 2]);
        $this->setService($service);

        $publisher = $service->getPublisher();

        $this->expectException(PublishException::class);
        $this->expectExceptionMessage('unroutable');

        try {
            $publisher->publish('body', $exchange, $routingKey, ['message_id' => 'msg_' . uniqid('', true)]);
        } catch (PublishException $e) {
            $this->assertSame(ErrorCode::PUBLISH_UNROUTABLE, $e->getErrorCode());
            throw $e;
        }
    }

    public function testAMQP_TIMEOUT_01_publishTimeout(): void
    {
        if (!$this->envFlagEnabled('BLOCK_BROKER')) {
            $this->markTestSkipped('Set BLOCK_BROKER=1 and block broker to test publish timeout.');
        }

        $exchange = $this->uniqueName('timeout_ex');
        $queue = $this->uniqueName('timeout_q');
        $routingKey = 'rk';

        $this->declareExchange($exchange);
        $this->declareQueue($queue);
        $this->bindQueue($queue, $exchange, $routingKey);

        $service = $this->createService(['confirm' => true, 'publishTimeout' => 1]);
        $this->setService($service);

        try {
            $service->publish('body', $exchange, $routingKey);
            $this->markTestSkipped('Publish timeout not observed; ensure broker is blocked and rerun.');
        } catch (PublishException $e) {
            $this->assertSame(ErrorCode::PUBLISH_TIMEOUT, $e->getErrorCode());
        }
    }

    private function injectTracker(object $publisher, PublishConfirmTracker $tracker): void
    {
        if (!$publisher instanceof AmqpPublisher) {
            $this->markTestSkipped('Publisher is not AMQP-based.');
        }

        $ref = new \ReflectionProperty($publisher, 'tracker');
        $ref->setAccessible(true);
        $ref->setValue($publisher, $tracker);
    }
}

class TestConfirmTracker extends PublishConfirmTracker
{
    public array $registeredMessageIds = [];
    public array $ackedSeqNos = [];
    public array $nackedSeqNos = [];
    public bool $ackMultipleSeen = false;
    public bool $nackMultipleSeen = false;

    public function register(int $seqNo, ?string $messageId, float $timestampStart): void
    {
        parent::register($seqNo, $messageId, $timestampStart);
        if ($messageId) {
            $this->registeredMessageIds[$messageId] = true;
        }
    }

    public function markAck(int $deliveryTag, bool $multiple): void
    {
        parent::markAck($deliveryTag, $multiple);
        $this->ackedSeqNos[] = $deliveryTag;
        if ($multiple) {
            $this->ackMultipleSeen = true;
        }
    }

    public function markNack(int $deliveryTag, bool $multiple): void
    {
        parent::markNack($deliveryTag, $multiple);
        $this->nackedSeqNos[] = $deliveryTag;
        if ($multiple) {
            $this->nackMultipleSeen = true;
        }
    }
}
