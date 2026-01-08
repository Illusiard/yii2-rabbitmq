<?php

namespace illusiard\rabbitmq\tests\integration;

/**
 * @group integration
 */
class XDeathIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_XDEATH_01_diagnosticHeader(): void
    {
        $deadQueue = $this->uniqueName('dead');
        $mainQueue = $this->uniqueName('main');
        $exchange = $this->uniqueName('dlx_ex');

        $this->declareExchange($exchange);
        $this->declareQueue($deadQueue);
        $this->declareQueue($mainQueue, true, [
            'x-dead-letter-exchange' => $exchange,
            'x-dead-letter-routing-key' => $deadQueue,
        ]);
        $this->bindQueue($deadQueue, $exchange, $deadQueue);

        $this->publishRaw('payload', '', $mainQueue);

        $channel = $this->getChannel();
        try {
            $message = $channel->basic_get($mainQueue, false);
            $this->assertNotNull($message);
            $channel->basic_reject($message->getDeliveryTag(), false);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }

        $deadMessage = $this->waitForMessage($deadQueue, 5);
        $this->assertNotNull($deadMessage);

        $properties = $deadMessage->get_properties();
        $headers = [];
        if (isset($properties['application_headers'])) {
            $headers = $properties['application_headers']->getNativeData();
        }

        $this->assertArrayHasKey('x-death', $headers);
    }
}
