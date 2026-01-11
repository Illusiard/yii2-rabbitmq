<?php

namespace illusiard\rabbitmq\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\console\DlqInspectController;
use illusiard\rabbitmq\console\HealthcheckController;
use illusiard\rabbitmq\console\SetupTopologyController;
use illusiard\rabbitmq\dlq\DlqService;

class ConsoleControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Yii::$container = new \yii\di\Container();
    }

    public function testDlqInspectJsonOutputIsValidAndNormalized(): void
    {
        $resource = fopen('php://memory', 'rb');
        $fakeService = new ConsoleTestDlqService([
            [
                'body' => 'demo',
                'headers' => [],
                'properties' => [
                    'application_headers' => new AMQPTable(['x-trace-id' => 't-1']),
                    'message_id' => 'msg-1',
                    'stream' => $resource,
                ],
                'meta' => [],
            ],
        ]);

        Yii::$container->set(DlqService::class, function () use ($fakeService) {
            return $fakeService;
        });
        Yii::$app->set('rabbitmq', new ConsoleTestRabbitMqService());

        $controller = new DlqInspectController('rabbitmq/dlq-inspect', Yii::$app);
        $controller->json = 1;

        ob_start();
        $exitCode = $controller->actionIndex('orders.dead');
        $output = trim(ob_get_clean());

        $this->assertSame(0, $exitCode);
        $data = json_decode($output, true);
        $this->assertIsArray($data);
        $this->assertSame('t-1', $data[0]['properties']['application_headers']['x-trace-id']);
        $this->assertNull($data[0]['properties']['stream']);

        fclose($resource);
    }

    public function testDlqInspectDestructiveRequiresForce(): void
    {
        $controller = new DlqInspectController('rabbitmq/dlq-inspect', Yii::$app);
        $controller->ack = 1;
        $controller->force = 0;

        $exitCode = $controller->actionIndex('orders.dead');

        $this->assertSame(1, $exitCode);
    }

    public function testHealthcheckExitCodeDependsOnPing(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->pingResult = true;
        Yii::$app->set('rabbitmq', $service);

        $controller = new HealthcheckController('rabbitmq/healthcheck', Yii::$app);
        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);

        $service->pingResult = false;
        $service->lastError = 'no-connection';
        $exitCode = $controller->actionIndex();

        $this->assertSame(1, $exitCode);
    }

    public function testSetupTopologyPassesDryRunAndStrict(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->topology = [
            'options' => [],
            'main' => [
                [
                    'queue' => 'orders',
                    'exchange' => 'orders-ex',
                    'routingKey' => 'orders',
                ],
            ],
        ];
        Yii::$app->set('rabbitmq', $service);

        $controller = new SetupTopologyController('rabbitmq/setup-topology', Yii::$app);
        $controller->dryRun = true;
        $controller->strict = true;

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($service->setupTopologyCalled);
        $this->assertTrue($service->lastTopology['options']['dryRun']);
        $this->assertTrue($service->lastTopology['options']['strict']);
    }
}

class ConsoleTestDlqService
{
    private array $items;

    public function __construct(array $items)
    {
        $this->items = $items;
    }

    public function inspect(string $queue, int $limit = 10, bool $acknowledge = false): array
    {
        return $this->items;
    }
}

class ConsoleTestRabbitMqService extends \yii\base\Component
{
    public array $topology = [];
    public bool $pingResult = true;
    public ?string $lastError = null;
    public array $profiles = [];
    public bool $setupTopologyCalled = false;
    public array $lastTopology = [];

    public function ping(int $timeout = 3): bool
    {
        return $this->pingResult;
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function forProfile(string $name): self
    {
        return $this;
    }

    public function setupTopology(array $config): void
    {
        $this->setupTopologyCalled = true;
        $this->lastTopology = $config;
    }
}
