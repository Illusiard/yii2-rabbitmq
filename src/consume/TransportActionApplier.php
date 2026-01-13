<?php

namespace illusiard\rabbitmq\consume;

use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use PhpAmqpLib\Message\AMQPMessage;

class TransportActionApplier
{
    public function apply(ConsumeResult $result, AMQPMessage $message): void
    {
        $channel = $message->getChannel();
        $tag = $message->getDeliveryTag();

        switch ($result->getAction()) {
            case ConsumeResult::ACTION_ACK:
                $channel->basic_ack($tag);
                return;
            case ConsumeResult::ACTION_REQUEUE:
                $channel->basic_reject($tag, true);
                return;
            case ConsumeResult::ACTION_REJECT:
                $channel->basic_reject($tag, $result->shouldRequeue());
                return;
            case ConsumeResult::ACTION_RETRY:
                $channel->basic_reject($tag, false);
                return;
            case ConsumeResult::ACTION_STOP:
                $channel->basic_reject($tag, false);
                return;
            default:
                $channel->basic_reject($tag, false);
                return;
        }
    }
}
