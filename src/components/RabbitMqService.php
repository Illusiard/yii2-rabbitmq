<?php

namespace illusiard\rabbitmq\components;

use illusiard\rabbitmq\amqp\AmqpConsumer;
use Yii;
use yii\base\Component;
use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\TopologyManager;
use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\exceptions\ConnectionException;
use illusiard\rabbitmq\exceptions\PublishException;
use illusiard\rabbitmq\exceptions\RabbitMqException;
use illusiard\rabbitmq\exceptions\ErrorCode;
use illusiard\rabbitmq\config\ConfigValidator;
use illusiard\rabbitmq\message\Envelope;
use illusiard\rabbitmq\message\MessageSerializerInterface;
use illusiard\rabbitmq\message\JsonMessageSerializer;
use illusiard\rabbitmq\consume\ExceptionClassifier;
use illusiard\rabbitmq\middleware\PublishPipeline;
use illusiard\rabbitmq\middleware\ConsumePipeline;
use illusiard\rabbitmq\middleware\PublishMiddlewareInterface;
use illusiard\rabbitmq\middleware\ConsumeMiddlewareInterface;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;
use illusiard\rabbitmq\amqp\LoggingReturnHandler;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\definitions\discovery\DiscoveryConfig;
use illusiard\rabbitmq\definitions\discovery\DefinitionsDiscovery;
use illusiard\rabbitmq\definitions\registry\ConsumerRegistry as DefinitionConsumerRegistry;
use illusiard\rabbitmq\definitions\registry\PublisherRegistry as DefinitionPublisherRegistry;
use illusiard\rabbitmq\definitions\registry\MiddlewareRegistry as DefinitionMiddlewareRegistry;
use illusiard\rabbitmq\definitions\registry\HandlerRegistry as DefinitionHandlerRegistry;
use InvalidArgumentException;
use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\TopologyBuilder;
use illusiard\rabbitmq\topology\TopologyApplier;

class RabbitMqService extends Component
{
    public string  $host                        = '127.0.0.1';
    public int     $port                        = 5672;
    public string  $user                        = 'guest';
    public string  $password                    = 'guest';
    public string  $vhost                       = '/';
    public int     $heartbeat                   = 30;
    public int     $readWriteTimeout            = 3;
    public int     $connectionTimeout           = 3;
    public ?array  $ssl                         = null;
    public array   $topology                    = [];
    public bool    $confirm                     = false;
    public bool    $mandatory                   = false;
    public int     $publishTimeout              = 5;
    public bool    $managedRetry                = false;
    public array   $retryPolicy                 = [];
    public array   $profiles                    = [];
    public string  $defaultProfile              = 'default';
    public         $serializer                  = JsonMessageSerializer::class;
    public array   $publishMiddlewares          = [];
    public array   $consumeMiddlewares          = [];
    public bool    $consumeFailFast             = true;
    public array   $fatalExceptionClasses       = [];
    public array   $recoverableExceptionClasses = [];
    public         $returnHandler               = LoggingReturnHandler::class;
    public bool    $returnHandlerEnabled        = true;
    public ?string $componentId                 = null;
    public array   $discovery                   = [];

    /** @var callable|null */
    public $connectionFactory;

    private ?ConnectionInterface        $connection         = null;
    private ?PublisherInterface         $publisher          = null;
    private ?MessageSerializerInterface $serializerInstance = null;
    private ?PublishPipeline            $publishPipeline    = null;
    private ?ConsumePipeline            $consumePipeline    = null;
    private ?ReturnHandlerInterface     $returnHandlerInstance = null;
    private ?string                     $activeProfile      = null;
    private ?string                     $lastError          = null;
    private ?DefinitionConsumerRegistry $consumerRegistry   = null;
    private ?DefinitionPublisherRegistry $publisherRegistry = null;
    private ?DefinitionMiddlewareRegistry $middlewareRegistry = null;
    private ?DefinitionHandlerRegistry $handlerRegistry = null;

    public function init()
    {
        parent::init();

        try {
            $validator = new ConfigValidator();
            $config    = [
                'amqp'                        => [
                    'host'              => $this->host,
                    'port'              => $this->port,
                    'user'              => $this->user,
                    'password'          => $this->password,
                    'vhost'             => $this->vhost,
                    'heartbeat'         => $this->heartbeat,
                    'readWriteTimeout'  => $this->readWriteTimeout,
                    'connectionTimeout' => $this->connectionTimeout,
                    'confirm'           => $this->confirm,
                    'mandatory'         => $this->mandatory,
                    'publishTimeout'    => $this->publishTimeout,
                ],
                'topology'                    => $this->topology,
                'publishMiddlewares'          => $this->publishMiddlewares,
                'consumeMiddlewares'          => $this->consumeMiddlewares,
                'consumeFailFast'             => $this->consumeFailFast,
                'fatalExceptionClasses'       => $this->fatalExceptionClasses,
                'recoverableExceptionClasses' => $this->recoverableExceptionClasses,
                'returnHandler'               => $this->returnHandler,
                'returnHandlerEnabled'        => $this->returnHandlerEnabled,
            ];

            if (!empty($this->profiles)) {
                $config['profiles']       = $this->profiles;
                $config['defaultProfile'] = $this->defaultProfile;
            }

            $validator->validate($config);
        } catch (RabbitMqException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RabbitMqException(
                'Config validation failed: ' . $e->getMessage(),
                ErrorCode::CONFIG_INVALID,
                0,
                $e
            );
        }
    }

    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        try {
            $this->getPublisher()->publish($body, $exchange, $routingKey, $properties, $headers);
        } catch (ConnectionException $e) {
            throw $e;
        } catch (PublishException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PublishException('Publish failed: ' . $e->getMessage(), ErrorCode::PUBLISH_FAILED, 0, $e);
        }
    }

    public function forProfile(string $name): self
    {
        $clone                = clone $this;
        $clone->activeProfile = $name;

        return $clone;
    }

    public function publishEnvelope(Envelope $env, string $exchange = '', string $routingKey = ''): void
    {
        $context = $this->buildPublishContext($exchange, $routingKey);

        $this->getPublishPipeline()->run($env, $context, function (Envelope $env) use ($exchange, $routingKey) {
            $serializer = $this->getSerializer();
            $body       = $serializer->encode($env);

            $properties = $env->getProperties();
            unset($properties['application_headers']);
            if (!isset($properties['content_type']) && $serializer instanceof JsonMessageSerializer) {
                $properties['content_type'] = 'application/json';
            }
            $properties['message_id'] = $env->getMessageId();
            if ($env->getCorrelationId() !== null) {
                $properties['correlation_id'] = $env->getCorrelationId();
            }

            $this->publish($body, $exchange, $routingKey, $properties, $env->getHeaders());
        });
    }

    public function publishRawWithMiddlewares(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        $env = new Envelope(
            null,
            $headers,
            $properties,
            null,
            $properties['correlation_id'] ?? null,
            $properties['message_id'] ?? null
        );

        $context = $this->buildPublishContext($exchange, $routingKey);
        $this->getPublishPipeline()->run($env, $context, function (Envelope $env) use ($body, $exchange, $routingKey) {
            $properties = $env->getProperties();
            unset($properties['application_headers']);

            if (!isset($properties['message_id']) && $env->getMessageId()) {
                $properties['message_id'] = $env->getMessageId();
            }
            if (!isset($properties['correlation_id']) && $env->getCorrelationId() !== null) {
                $properties['correlation_id'] = $env->getCorrelationId();
            }

            $this->publish($body, $exchange, $routingKey, $properties, $env->getHeaders());
        });
    }

    public function publishJson(
        mixed $payload,
        string $exchange = '',
        string $routingKey = '',
        array $options = []
    ): void {
        $env = new Envelope(
            $payload,
            $options['headers'] ?? [],
            $options['properties'] ?? [],
            $options['type'] ?? null,
            $options['correlationId'] ?? null
        );

        $this->publishEnvelope($env, $exchange, $routingKey);
    }

    public function decodeEnvelope(string $body, array $meta = []): Envelope
    {
        return $this->getSerializer()->decode($body, $meta);
    }

    public function getConnection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    public function getPublisher(): PublisherInterface
    {
        if ($this->publisher === null) {
            $this->publisher = $this->ensureConnection()->getPublisher();
        }

        return $this->publisher;
    }

    public function consume(string $queue, $handler, array $options = []): void
    {
        $options = $this->normalizeConsumeOptions($options);
        $prefetch = $options['prefetch'];

        $handlerClass = $this->resolveHandlerClass($handler);
        if (is_string($handler)) {
            $handler = Yii::createObject($handler);
        }
        if (!is_callable($handler)) {
            throw new InvalidArgumentException('Handler must be callable via __invoke.');
        }

        $context        = $this->buildConsumeContext($queue, $handlerClass);
        $pipeline       = $this->getConsumePipeline();
        $wrappedHandler = function (string $body, array $meta) use ($handler, $pipeline, $context) {
            return $pipeline->run($body, $meta, $context, function (string $body, array $meta) use ($handler) {
                return $handler($body, $meta);
            });
        };

        $consumer = $this->ensureConnection()->getConsumer();
        if ($consumer instanceof AmqpConsumer) {
            $consumer->setManagedRetry($this->managedRetry, $this->retryPolicy, $this->getPublisher());
        }

        $consumer->consume($queue, $wrappedHandler, $prefetch);
    }

    public function createRunner(): ConsumeRunner
    {
        return new ConsumeRunner($this);
    }

    public function getConsumerRegistry(): DefinitionConsumerRegistry
    {
        if ($this->consumerRegistry !== null) {
            return $this->consumerRegistry;
        }

        $config = $this->getDiscoveryConfigOrThrow();
        $discovery = new DefinitionsDiscovery($config);
        $this->consumerRegistry = $discovery->discoverConsumers();

        return $this->consumerRegistry;
    }

    public function getPublisherRegistry(): DefinitionPublisherRegistry
    {
        if ($this->publisherRegistry !== null) {
            return $this->publisherRegistry;
        }

        $config = $this->getDiscoveryConfigOrThrow();
        $discovery = new DefinitionsDiscovery($config);
        $this->publisherRegistry = $discovery->discoverPublishers();

        return $this->publisherRegistry;
    }

    public function getMiddlewareRegistry(): DefinitionMiddlewareRegistry
    {
        if ($this->middlewareRegistry !== null) {
            return $this->middlewareRegistry;
        }

        $config = $this->getDiscoveryConfigOrThrow();
        $discovery = new DefinitionsDiscovery($config);
        $this->middlewareRegistry = $discovery->discoverMiddlewares();

        return $this->middlewareRegistry;
    }

    public function getHandlerRegistry(): DefinitionHandlerRegistry
    {
        if ($this->handlerRegistry !== null) {
            return $this->handlerRegistry;
        }

        $config = $this->getDiscoveryConfigOrThrow();
        if (!$config->hasHandlersPath()) {
            throw new InvalidArgumentException('Handler discovery is disabled because no handlers path is configured.');
        }

        $discovery = new DefinitionsDiscovery($config);
        $this->handlerRegistry = $discovery->discoverHandlers();

        return $this->handlerRegistry;
    }

    private function normalizeConsumeOptions(array $options): array
    {
        $prefetch = $options['prefetch'] ?? 1;

        if (isset($options['managedRetry'])) {
            $this->managedRetry = (bool)$options['managedRetry'];
        }

        if (isset($options['retryPolicy']) && is_array($options['retryPolicy'])) {
            $this->retryPolicy = $options['retryPolicy'];
        }

        $pipelineChanged = false;
        if (isset($options['consumeFailFast'])) {
            $this->consumeFailFast = (bool)$options['consumeFailFast'];
            $pipelineChanged = true;
        }

        if (isset($options['fatalExceptionClasses']) && is_array($options['fatalExceptionClasses'])) {
            $this->fatalExceptionClasses = $options['fatalExceptionClasses'];
            $pipelineChanged = true;
        }

        if (isset($options['recoverableExceptionClasses']) && is_array($options['recoverableExceptionClasses'])) {
            $this->recoverableExceptionClasses = $options['recoverableExceptionClasses'];
            $pipelineChanged = true;
        }

        $middlewares = $options['consumeMiddlewares'] ?? $options['middlewares'] ?? null;
        if (is_array($middlewares)) {
            $this->consumeMiddlewares = $middlewares;
            $pipelineChanged = true;
        }

        if ($pipelineChanged) {
            $this->consumePipeline = null;
        }

        return [
            'prefetch' => (int)$prefetch,
        ];
    }

    private function resolveHandlerClass($handler): ?string
    {
        if (is_string($handler)) {
            return $handler;
        }

        if (is_object($handler)) {
            return get_class($handler);
        }

        if (is_array($handler) && isset($handler[0])) {
            $target = $handler[0];
            if (is_object($target)) {
                return get_class($target);
            }
            if (is_string($target)) {
                return $target;
            }
        }

        return null;
    }

    public function tick(float $timeout = 0.0): void
    {
        if ($this->publisher === null && $this->connection === null) {
            return;
        }

        $publisher = $this->getPublisher();
        if (method_exists($publisher, 'tick')) {
            $publisher->tick($timeout);
        }
    }

    public function setupTopology(array $config): void
    {
        $topology = (new TopologyBuilder())->buildFromConfig($config);
        $topology->validate();
        $this->applyTopology($topology, false);
    }

    public function buildTopology(): Topology
    {
        return (new TopologyBuilder())->buildFromService($this);
    }

    public function applyTopology(Topology $topology, bool $dryRun = false): void
    {
        $connection = $this->ensureConnection();
        if (!$connection instanceof AmqpConnection) {
            throw new ConnectionException('Topology setup requires AmqpConnection.', ErrorCode::CONNECTION_FAILED);
        }

        $channel = $connection->getAmqpConnection()->channel();
        try {
            (new TopologyApplier())->apply($topology, $channel, $dryRun);
        } finally {
            if ($channel->is_open()) {
                $channel->close();
            }
        }
    }

    public function ping(int $timeout = 3): bool
    {
        $this->lastError = null;
        $connection      = null;

        try {
            $config                      = $this->getConnectionConfig();
            $config['connectionTimeout'] = $timeout;
            $config['readWriteTimeout']  = $timeout;

            $connection = $this->buildConnectionForPing($config);
            $connection->connect();

            if ($connection instanceof AmqpConnection) {
                $channel = $connection->getAmqpConnection()->channel();
                $channel->close();
            } elseif (method_exists($connection, 'getAmqpConnection')) {
                $amqp = $connection->getAmqpConnection();
                if (is_object($amqp) && method_exists($amqp, 'channel')) {
                    $channel = $amqp->channel();
                    if (is_object($channel) && method_exists($channel, 'close')) {
                        $channel->close();
                    }
                }
            }

            return true;
        } catch (\Throwable $e) {
            $this->lastError = $this->formatError($e);

            return false;
        } finally {
            if ($connection instanceof ConnectionInterface) {
                try {
                    $connection->close();
                } catch (\Throwable $e) {
                }
            }
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    private function ensureConnection(): ConnectionInterface
    {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            try {
                $connection->connect();
            } catch (\Throwable $e) {
                throw new ConnectionException(
                    'Connection failed: ' . $e->getMessage(),
                    ErrorCode::CONNECTION_FAILED,
                    0,
                    $e
                );
            }
        }

        return $connection;
    }

    private function createConnection(): ConnectionInterface
    {
        if (is_callable($this->connectionFactory)) {
            $factory = $this->connectionFactory;

            return $factory($this->getConnectionConfig());
        }

        return new AmqpConnection($this->getConnectionConfig());
    }

    private function buildConnectionForPing(array $config): ConnectionInterface
    {
        if (is_callable($this->connectionFactory)) {
            $factory = $this->connectionFactory;

            return $factory($config);
        }

        return new AmqpConnection($config);
    }

    private function formatError(\Throwable $e): string
    {
        $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::GENERIC;

        return $code . ' ' . get_class($e) . ': ' . $e->getMessage();
    }

    public function getSerializer(): MessageSerializerInterface
    {
        if ($this->serializerInstance !== null) {
            return $this->serializerInstance;
        }

        if ($this->serializer instanceof MessageSerializerInterface) {
            $this->serializerInstance = $this->serializer;

            return $this->serializerInstance;
        }

        $this->serializerInstance = Yii::createObject($this->serializer);

        return $this->serializerInstance;
    }

    private function getReturnHandler(): ?ReturnHandlerInterface
    {
        if (!$this->returnHandlerEnabled) {
            return null;
        }

        if ($this->returnHandlerInstance !== null) {
            return $this->returnHandlerInstance;
        }

        if ($this->returnHandler instanceof ReturnHandlerInterface) {
            $this->returnHandlerInstance = $this->returnHandler;
            return $this->returnHandlerInstance;
        }

        if ($this->returnHandler === null) {
            return null;
        }

        $instance = Yii::createObject($this->returnHandler);
        if (!$instance instanceof ReturnHandlerInterface) {
            throw new InvalidArgumentException('returnHandler must implement ReturnHandlerInterface.');
        }

        $this->returnHandlerInstance = $instance;
        return $this->returnHandlerInstance;
    }

    private function getPublishPipeline(): PublishPipeline
    {
        if ($this->publishPipeline !== null) {
            return $this->publishPipeline;
        }

        $middlewares           =
            $this->resolveMiddlewares($this->publishMiddlewares, PublishMiddlewareInterface::class);
        $this->publishPipeline = new PublishPipeline($middlewares);

        return $this->publishPipeline;
    }

    private function getConsumePipeline(): ConsumePipeline
    {
        if ($this->consumePipeline !== null) {
            return $this->consumePipeline;
        }

        $middlewares           =
            $this->resolveMiddlewares($this->consumeMiddlewares, ConsumeMiddlewareInterface::class);
        $classifier            = new ExceptionClassifier(
            $this->consumeFailFast,
            $this->fatalExceptionClasses,
            $this->recoverableExceptionClasses
        );
        $this->consumePipeline = new ConsumePipeline($middlewares, $classifier);

        return $this->consumePipeline;
    }

    private function resolveMiddlewares(array $configs, string $interface): array
    {
        $middlewares = [];

        foreach ($configs as $config) {
            $instance = $config;
            if (!is_object($instance)) {
                $instance = Yii::createObject($config);
            }

            if (!$instance instanceof $interface) {
                throw new InvalidArgumentException('Middleware must implement ' . $interface);
            }

            $middlewares[] = $instance;
        }

        return $middlewares;
    }

    private function buildPublishContext(string $exchange, string $routingKey): array
    {
        return [
            'exchange'     => $exchange,
            'routingKey'   => $routingKey,
            'queue'        => null,
            'handlerClass' => null,
            'componentId'  => $this->componentId,
            'timestamp'    => time(),
        ];
    }

    private function buildConsumeContext(string $queue, ?string $handlerClass): array
    {
        return [
            'exchange'     => null,
            'routingKey'   => null,
            'queue'        => $queue,
            'handlerClass' => $handlerClass,
            'componentId'  => $this->componentId,
            'timestamp'    => time(),
        ];
    }

    private function getConnectionConfig(): array
    {
        $base = [
            'host'              => $this->host,
            'port'              => $this->port,
            'user'              => $this->user,
            'password'          => $this->password,
            'vhost'             => $this->vhost,
            'heartbeat'         => $this->heartbeat,
            'readWriteTimeout'  => $this->readWriteTimeout,
            'connectionTimeout' => $this->connectionTimeout,
            'ssl'               => $this->ssl,
            'confirm'           => $this->confirm,
            'mandatory'         => $this->mandatory,
            'publishTimeout'    => $this->publishTimeout,
            'returnHandler'     => $this->getReturnHandler(),
            'returnHandlerEnabled' => $this->returnHandlerEnabled,
        ];

        if (empty($this->profiles)) {
            return $base;
        }

        $profileName = $this->resolveProfileName();
        if (!isset($this->profiles[$profileName]) || !is_array($this->profiles[$profileName])) {
            throw new RabbitMqException('Profile not found: ' . $profileName, ErrorCode::CONFIG_INVALID);
        }

        return array_merge($base, $this->profiles[$profileName]);
    }

    private function resolveProfileName(): string
    {
        if ($this->activeProfile !== null) {
            return $this->activeProfile;
        }

        if (isset($this->profiles[$this->defaultProfile])) {
            return $this->defaultProfile;
        }

        $keys = array_keys($this->profiles);

        return (string)reset($keys);
    }

    public function __clone()
    {
        $this->connection         = null;
        $this->publisher          = null;
        $this->serializerInstance = null;
        $this->publishPipeline    = null;
        $this->consumePipeline    = null;
        $this->returnHandlerInstance = null;
        $this->lastError          = null;
        $this->consumerRegistry   = null;
        $this->publisherRegistry  = null;
        $this->middlewareRegistry = null;
        $this->handlerRegistry    = null;
    }

    private function getDiscoveryConfigOrThrow(): DiscoveryConfig
    {
        $config = new DiscoveryConfig($this->discovery);
        if (!$config->isEnabled()) {
            throw new InvalidArgumentException('Discovery is disabled.');
        }

        if (empty($config->getPaths())) {
            throw new InvalidArgumentException('Discovery paths are not configured.');
        }

        return $config;
    }
}
