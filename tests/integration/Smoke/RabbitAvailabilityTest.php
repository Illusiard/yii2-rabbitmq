<?php

namespace illusiard\rabbitmq\tests\integration\Smoke;

use PHPUnit\Framework\TestCase;
use illusiard\rabbitmq\components\RabbitMqService;

/**
 * @group integration
 */
class RabbitAvailabilityTest extends TestCase
{
    public function testRabbitMqIsAvailable(): void
    {
        $service = new RabbitMqService($this->getRabbitConfig());
        $ok = false;
        $error = null;

        try {
            $ok = $service->ping(1);
            if (!$ok) {
                $error = $service->getLastError();
            }
        } catch (\Throwable $e) {
            $error = get_class($e) . ': ' . $e->getMessage();
        }

        $message = 'RabbitMQ unavailable at ' . $this->getConnectionDsnForMessage();
        if ($error) {
            $message .= ' (' . $error . ')';
        }

        if (!$ok) {
            $this->markTestSkipped($message);
        }

        $this->assertTrue($ok);
    }

    private function getRabbitConfig(): array
    {
        return [
            'host' => getenv('RABBIT_HOST') ?: 'localhost',
            'port' => getenv('RABBIT_PORT') ? (int)getenv('RABBIT_PORT') : 5672,
            'user' => getenv('RABBIT_USER') ?: 'guest',
            'password' => getenv('RABBIT_PASSWORD') ?: 'guest',
            'vhost' => getenv('RABBIT_VHOST') ?: '/',
            'heartbeat' => 30,
            'readWriteTimeout' => 3,
            'connectionTimeout' => 3,
            'confirm' => false,
            'mandatory' => false,
            'publishTimeout' => 5,
            'managedRetry' => false,
            'retryPolicy' => [],
            'publishMiddlewares' => [],
            'consumeMiddlewares' => [],
        ];
    }

    private function getConnectionDsnForMessage(): string
    {
        $config = $this->getRabbitConfig();
        return sprintf(
            '%s:%d vhost=%s user=%s',
            $config['host'],
            $config['port'],
            $config['vhost'],
            $config['user']
        );
    }
}
