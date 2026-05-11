<?php

namespace illusiard\rabbitmq\tests\integration\fixtures;

use illusiard\rabbitmq\amqp\PublishConfirmTracker;

class TestConfirmTracker extends PublishConfirmTracker
{
    public array $registeredMessageIds = [];
    public array $ackedSeqNos = [];
    public array $nackedSeqNos = [];
    public bool $ackMultipleSeen = false;
    public bool $nackMultipleSeen = false;

    public function register(
        int $seqNo,
        ?string $messageId,
        float $timestampStart,
        ?string $correlationId = null,
        ?string $exchange = null,
        ?string $routingKey = null
    ): void {
        parent::register($seqNo, $messageId, $timestampStart, $correlationId, $exchange, $routingKey);
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
