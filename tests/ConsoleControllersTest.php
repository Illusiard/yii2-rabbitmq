<?php

namespace illusiard\rabbitmq\tests;

use Yii;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\console\DlqInspectController;
use illusiard\rabbitmq\console\HealthcheckController;
use illusiard\rabbitmq\console\SetupTopologyController;
use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\console\DlqPurgeController;
use illusiard\rabbitmq\console\DlqReplayController;
use illusiard\rabbitmq\console\ConsumersController;
use illusiard\rabbitmq\console\PublishersController;
use illusiard\rabbitmq\console\MiddlewaresController;
use illusiard\rabbitmq\console\TopologyApplyController;
use illusiard\rabbitmq\console\TopologyStatusController;
use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use illusiard\rabbitmq\topology\BindingDefinition;

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

        $controller = new class('rabbitmq/dlq-inspect', Yii::$app) extends DlqInspectController {
            public string $buffer = '';

            public function stdout($string)
            {
                $this->buffer .= $string;
                return strlen($string);
            }
        };
        $controller->json = 1;

        $exitCode = $controller->actionIndex('orders.dead');
        $output = trim($controller->buffer);

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

    public function testDlqPurgeRequiresForce(): void
    {
        $controller = new DlqPurgeController('rabbitmq/dlq-purge', Yii::$app);
        $controller->force = 0;

        $exitCode = $controller->actionIndex('orders.dead');

        $this->assertSame(1, $exitCode);
    }

    public function testDlqReplayRequiresExchangeAndRoutingKey(): void
    {
        $controller = new DlqReplayController('rabbitmq/dlq-replay', Yii::$app);
        $controller->exchange = '';
        $controller->routingKey = '';

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

    public function testControllersExposeComponentOption(): void
    {
        $controllers = [
            new ConsumeController('rabbitmq/consume', Yii::$app),
            new ConsumersController('rabbitmq/consumers', Yii::$app),
            new PublishersController('rabbitmq/publishers', Yii::$app),
            new MiddlewaresController('rabbitmq/middlewares', Yii::$app),
            new DlqInspectController('rabbitmq/dlq-inspect', Yii::$app),
            new DlqPurgeController('rabbitmq/dlq-purge', Yii::$app),
            new DlqReplayController('rabbitmq/dlq-replay', Yii::$app),
            new HealthcheckController('rabbitmq/healthcheck', Yii::$app),
            new SetupTopologyController('rabbitmq/setup-topology', Yii::$app),
            new TopologyApplyController('rabbitmq/topology-apply', Yii::$app),
            new TopologyStatusController('rabbitmq/topology-status', Yii::$app),
        ];

        foreach ($controllers as $controller) {
            $options = $controller->options('index');
            $this->assertContains('component', $options);

            $aliases = $controller->optionAliases();
            $this->assertArrayHasKey('c', $aliases);
            $this->assertSame('component', $aliases['c']);
        }
    }

    public function testControllerOptionsIncludeExpectedFlags(): void
    {
        $matrix = [
            ConsumeController::class => [
                'managedRetry',
                'retryPolicy',
                'consumeFailFast',
                'fatalExceptionClasses',
                'recoverableExceptionClasses',
                'readyLock',
            ],
            DlqInspectController::class => ['limit', 'json', 'ack', 'force'],
            DlqReplayController::class => ['exchange', 'routingKey', 'limit'],
            DlqPurgeController::class => ['force'],
            HealthcheckController::class => ['profile', 'timeout', 'json'],
            SetupTopologyController::class => ['dryRun', 'strict'],
            TopologyApplyController::class => ['dryRun', 'strict'],
            TopologyStatusController::class => ['strict'],
        ];

        foreach ($matrix as $class => $expected) {
            $controller = new $class('rabbitmq/test', Yii::$app);
            $options = $controller->options('index');

            foreach ($expected as $option) {
                $this->assertContains($option, $options);
            }
        }
    }

    public function testComponentOverrideIsUsed(): void
    {
        $default = new ConsoleTestRabbitMqService();
        $default->pingResult = false;

        $custom = new ConsoleTestRabbitMqService();
        $custom->pingResult = true;

        Yii::$app->set('rabbitmq', $default);
        Yii::$app->set('rabbit2', $custom);

        $controller = new HealthcheckController('rabbitmq/healthcheck', Yii::$app);
        $controller->component = 'rabbit2';

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertFalse($default->pingCalled);
        $this->assertTrue($custom->pingCalled);
    }

    public function testSetupTopologyPassesDryRunAndStrict(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('orders-ex', 'direct'));
        $topology->addQueue(new QueueDefinition('orders'));
        $topology->addBinding(new BindingDefinition('orders-ex', 'orders', 'orders'));
        $service->buildTopologyReturn = $topology;
        Yii::$app->set('rabbitmq', $service);

        $controller = new SetupTopologyController('rabbitmq/setup-topology', Yii::$app);
        $controller->dryRun = true;
        $controller->strict = true;

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($service->buildTopologyCalled);
        $this->assertTrue($service->applyTopologyCalled);
        $this->assertTrue($service->lastDryRun);
    }

    public function testTopologyApplyRequiresNonEmptyTopology(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->buildTopologyReturn = new Topology();
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyApplyController('rabbitmq/topology-apply', Yii::$app);
        $exitCode = $controller->actionIndex();

        $this->assertSame(1, $exitCode);
    }

    public function testTopologyStatusRequiresNonEmptyTopology(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->buildTopologyReturn = new Topology();
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyStatusController('rabbitmq/topology-status', Yii::$app);
        $exitCode = $controller->actionIndex();

        $this->assertSame(1, $exitCode);
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

class ConsoleTestRabbitMqService extends \illusiard\rabbitmq\components\RabbitMqService
{
    public array $topology = [];
    public bool $pingResult = true;
    public ?string $lastError = null;
    public array $profiles = [];
    public bool $buildTopologyCalled = false;
    public bool $applyTopologyCalled = false;
    public ?Topology $buildTopologyReturn = null;
    public bool $lastDryRun = false;
    public bool $pingCalled = false;

    public function ping(int $timeout = 3): bool
    {
        $this->pingCalled = true;
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

    public function buildTopology(): Topology
    {
        $this->buildTopologyCalled = true;
        if ($this->buildTopologyReturn instanceof Topology) {
            return $this->buildTopologyReturn;
        }

        return new Topology();
    }

    public function applyTopology(Topology $topology, bool $dryRun = false): void
    {
        $this->applyTopologyCalled = true;
        $this->lastDryRun = $dryRun;
    }
}
