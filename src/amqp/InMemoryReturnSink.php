<?php

namespace illusiard\rabbitmq\amqp;

class InMemoryReturnSink implements ReturnSinkInterface
{
    private int $maxSize;
    /** @var ReturnedMessage[] */
    private array $buffer = [];
    private int $totalReturns = 0;
    private int $droppedReturns = 0;
    private int $ackCount = 0;
    private int $nackCount = 0;

    public function __construct(int $maxSize = 1000)
    {
        $this->maxSize = $maxSize;
    }

    public function onReturned(ReturnedMessage $event): void
    {
        $this->totalReturns++;
        if ($this->maxSize <= 0) {
            $this->droppedReturns++;
            return;
        }

        $this->buffer[] = $event;
        if (count($this->buffer) > $this->maxSize) {
            array_shift($this->buffer);
            $this->droppedReturns++;
        }
    }

    public function onAck(int $deliveryTag, bool $multiple): void
    {
        $this->ackCount++;
    }

    public function onNack(int $deliveryTag, bool $multiple): void
    {
        $this->nackCount++;
    }

    public function drainReturns(): array
    {
        $items = $this->buffer;
        $this->buffer = [];

        return $items;
    }

    public function getStats(): array
    {
        return [
            'totalReturns' => $this->totalReturns,
            'droppedReturns' => $this->droppedReturns,
            'bufferedReturns' => count($this->buffer),
            'ackCount' => $this->ackCount,
            'nackCount' => $this->nackCount,
        ];
    }
}
