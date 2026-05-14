<?php

namespace illusiard\rabbitmq\tests;

use JsonException;
use Yii;
use PHPUnit\Framework\TestCase;
use PhpAmqpLib\Wire\AMQPTable;
use illusiard\rabbitmq\Bootstrap;
use illusiard\rabbitmq\Module;
use illusiard\rabbitmq\console\DlqInspectController;
use illusiard\rabbitmq\console\HealthcheckController;
use illusiard\rabbitmq\definitions\registry\ConsumerRegistry;
use illusiard\rabbitmq\definitions\registry\PublisherRegistry;
use illusiard\rabbitmq\dlq\DlqService;
use illusiard\rabbitmq\console\ConsumeController;
use illusiard\rabbitmq\console\DlqPurgeController;
use illusiard\rabbitmq\console\DlqReplayController;
use illusiard\rabbitmq\console\ConsumersController;
use illusiard\rabbitmq\console\PublishersController;
use illusiard\rabbitmq\console\MiddlewaresController;
use illusiard\rabbitmq\console\TopologyApplyController;
use illusiard\rabbitmq\console\TopologyStatusController;
use illusiard\rabbitmq\tests\fixtures\BufferingDlqInspectController;
use illusiard\rabbitmq\tests\fixtures\ConsoleTestDlqService;
use illusiard\rabbitmq\tests\fixtures\ConsoleTestRabbitMqService;
use illusiard\rabbitmq\tests\fixtures\TestConsumerDefinition;
use illusiard\rabbitmq\tests\fixtures\TestPublisherDefinition;
use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\ExchangeDefinition;
use illusiard\rabbitmq\topology\QueueDefinition;
use illusiard\rabbitmq\topology\BindingDefinition;
use yii\base\InvalidConfigException;
use yii\di\Container;

class ConsoleControllersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Yii::$container = new Container();
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
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

        $controller = new BufferingDlqInspectController('rabbitmq/dlq-inspect', Yii::$app);
        $controller->json = 1;

        $exitCode = $controller->actionIndex('orders.dead');
        $output = trim($controller->buffer);

        $this->assertSame(0, $exitCode);
        $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
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

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
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

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testBootstrapRegistersDefaultComponentAndConsoleModule(): void
    {
        Yii::$app->setModule('rabbitmq', null);

        (new Bootstrap())->bootstrap(Yii::$app);

        $this->assertTrue(Yii::$app->has('rabbitmq'));
        $this->assertTrue(Yii::$app->hasModule('rabbitmq'));
        $this->assertInstanceOf(Module::class, Yii::$app->getModule('rabbitmq'));
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
            TopologyApplyController::class => ['dryRun', 'strict'],
            TopologyStatusController::class => ['strict', 'json'],
            ConsumersController::class => ['json'],
            PublishersController::class => ['json'],
        ];

        foreach ($matrix as $class => $expected) {
            $controller = new $class('rabbitmq/test', Yii::$app);
            $options = $controller->options('index');

            foreach ($expected as $option) {
                $this->assertContains($option, $options);
            }
        }
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
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

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testTopologyApplyPassesDryRunAndStrict(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('orders-ex', 'direct'));
        $topology->addQueue(new QueueDefinition('orders'));
        $topology->addBinding(new BindingDefinition('orders-ex', 'orders', 'orders'));
        $service->buildTopologyReturn = $topology;
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyApplyController('rabbitmq/topology-apply', Yii::$app);
        $controller->dryRun = true;
        $controller->strict = true;

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($service->buildTopologyCalled);
        $this->assertTrue($service->applyTopologyCalled);
        $this->assertTrue($service->lastDryRun);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testTopologyApplyDefaultsToDryRun(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('orders-ex', 'direct'));
        $topology->addQueue(new QueueDefinition('orders'));
        $service->buildTopologyReturn = $topology;
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyApplyController('rabbitmq/topology-apply', Yii::$app);

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertTrue($service->applyTopologyCalled);
        $this->assertTrue($service->lastDryRun);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testConsumersJsonOutput(): void
    {
        $service = new class extends ConsoleTestRabbitMqService {
            public function getConsumerRegistry(): ConsumerRegistry
            {
                return new ConsumerRegistry([
                    'orders' => TestConsumerDefinition::class,
                ]);
            }
        };
        Yii::$app->set('rabbitmq', $service);

        $controller = new class ('rabbitmq/consumers', Yii::$app) extends ConsumersController {
            public string $buffer = '';

            public function stdout($string): int
            {
                $this->buffer .= $string;
                return strlen($string);
            }
        };
        $controller->json = 1;

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'orders' => TestConsumerDefinition::class,
        ], json_decode($controller->buffer, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testPublishersJsonOutput(): void
    {
        $service = new class extends ConsoleTestRabbitMqService {
            public function getPublisherRegistry(): PublisherRegistry
            {
                return new PublisherRegistry([
                    'orders' => TestPublisherDefinition::class,
                ]);
            }
        };
        Yii::$app->set('rabbitmq', $service);

        $controller = new class ('rabbitmq/publishers', Yii::$app) extends PublishersController {
            public string $buffer = '';

            public function stdout($string): int
            {
                $this->buffer .= $string;
                return strlen($string);
            }
        };
        $controller->json = 1;

        $exitCode = $controller->actionIndex();

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'orders' => TestPublisherDefinition::class,
        ], json_decode($controller->buffer, true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testTopologyApplyRequiresNonEmptyTopology(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->buildTopologyReturn = new Topology();
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyApplyController('rabbitmq/topology-apply', Yii::$app);
        $exitCode = $controller->actionIndex();

        $this->assertSame(1, $exitCode);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     */
    public function testTopologyStatusRequiresNonEmptyTopology(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $service->buildTopologyReturn = new Topology();
        Yii::$app->set('rabbitmq', $service);

        $controller = new TopologyStatusController('rabbitmq/topology-status', Yii::$app);
        $exitCode = $controller->actionIndex();

        $this->assertSame(1, $exitCode);
    }

    /**
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function testTopologyStatusJsonOutput(): void
    {
        $service = new ConsoleTestRabbitMqService();
        $topology = new Topology();
        $topology->addExchange(new ExchangeDefinition('orders-ex', 'direct'));
        $topology->addQueue(new QueueDefinition('orders'));
        $topology->addBinding(new BindingDefinition('orders-ex', 'orders', 'orders'));
        $service->buildTopologyReturn = $topology;
        Yii::$app->set('rabbitmq', $service);

        $controller = new class ('rabbitmq/topology-status', Yii::$app) extends TopologyStatusController {
            public string $buffer = '';

            public function stdout($string): int
            {
                $this->buffer .= $string;
                return strlen($string);
            }
        };
        $controller->json = 1;

        $exitCode = $controller->actionIndex();
        $data = json_decode($controller->buffer, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exitCode);
        $this->assertSame('orders-ex', $data['exchanges'][0]['name']);
        $this->assertSame('orders', $data['queues'][0]['name']);
        $this->assertSame('orders', $data['bindings'][0]['routingKey']);
    }
}
