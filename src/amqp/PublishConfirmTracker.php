<?php

namespace illusiard\rabbitmq\amqp;

class PublishConfirmTracker
{
    private array $inflight = [];
    private array $messageIdToSeq = [];
    private array $correlationIdToSeq = [];
    private int $localSeqNo = 0;

    public function nextLocalSeqNo(): int
    {
        $this->localSeqNo++;
        return $this->localSeqNo;
    }

    public function register(
        int $seqNo,
        ?string $messageId,
        float $timestampStart,
        ?string $correlationId = null,
        ?string $exchange = null,
        ?string $routingKey = null
    ): void
    {
        $this->inflight[$seqNo] = [
            'acked' => false,
            'nacked' => false,
            'returned' => false,
            'returnInfo' => null,
            'timestampStart' => $timestampStart,
            'messageId' => $messageId,
            'correlationId' => $correlationId,
            'exchange' => $exchange,
            'routingKey' => $routingKey,
        ];

        if ($messageId !== null && $messageId !== '') {
            $this->messageIdToSeq[$messageId] = $seqNo;
        }

        if ($correlationId !== null && $correlationId !== '' && !isset($this->correlationIdToSeq[$correlationId])) {
            $this->correlationIdToSeq[$correlationId] = $seqNo;
        }
    }

    public function markAck(int $deliveryTag, bool $multiple): void
    {
        $this->markByDeliveryTag($deliveryTag, $multiple, function (array $state): array {
            $state['acked'] = true;
            return $state;
        });
    }

    public function markNack(int $deliveryTag, bool $multiple): void
    {
        $this->markByDeliveryTag($deliveryTag, $multiple, function (array $state): array {
            $state['nacked'] = true;
            return $state;
        });
    }

    public function markReturned(string $messageId, array $returnInfo): ?int
    {
        if (!isset($this->messageIdToSeq[$messageId])) {
            return null;
        }

        $seqNo = $this->messageIdToSeq[$messageId];
        if (!isset($this->inflight[$seqNo])) {
            return null;
        }

        $this->inflight[$seqNo]['returned'] = true;
        $this->inflight[$seqNo]['returnInfo'] = $returnInfo;

        return $seqNo;
    }

    public function markReturnedByCorrelationId(string $correlationId, array $returnInfo): ?int
    {
        if (!isset($this->correlationIdToSeq[$correlationId])) {
            return null;
        }

        $seqNo = $this->correlationIdToSeq[$correlationId];
        if (!isset($this->inflight[$seqNo])) {
            return null;
        }

        $this->inflight[$seqNo]['returned'] = true;
        $this->inflight[$seqNo]['returnInfo'] = $returnInfo;

        return $seqNo;
    }

    public function get(int $seqNo): ?array
    {
        return $this->inflight[$seqNo] ?? null;
    }

    public function remove(int $seqNo): void
    {
        if (!isset($this->inflight[$seqNo])) {
            return;
        }

        $messageId = $this->inflight[$seqNo]['messageId'] ?? null;
        if (is_string($messageId) && $messageId !== '') {
            unset($this->messageIdToSeq[$messageId]);
        }

        $correlationId = $this->inflight[$seqNo]['correlationId'] ?? null;
        if (is_string($correlationId) && $correlationId !== '') {
            unset($this->correlationIdToSeq[$correlationId]);
        }

        unset($this->inflight[$seqNo]);
    }

    public function clear(): void
    {
        $this->inflight = [];
        $this->messageIdToSeq = [];
        $this->correlationIdToSeq = [];
    }

    public function count(): int
    {
        return count($this->inflight);
    }

    private function markByDeliveryTag(int $deliveryTag, bool $multiple, callable $updater): void
    {
        if ($multiple) {
            foreach ($this->inflight as $seqNo => $state) {
                if ($seqNo <= $deliveryTag) {
                    $this->inflight[$seqNo] = $updater($state);
                }
            }
            return;
        }

        if (isset($this->inflight[$deliveryTag])) {
            $this->inflight[$deliveryTag] = $updater($this->inflight[$deliveryTag]);
        }
    }

    public function findSeqNoByMessageId(string $messageId): ?int
    {
        return $this->messageIdToSeq[$messageId] ?? null;
    }

    public function findSeqNoByCorrelationId(string $correlationId): ?int
    {
        return $this->correlationIdToSeq[$correlationId] ?? null;
    }
}
