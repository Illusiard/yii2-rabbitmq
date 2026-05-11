<?php

namespace illusiard\rabbitmq\components;

use Exception;
use JsonException;
use ReflectionException;
use ReflectionFunction;
use Throwable;
use Yii;
use yii\base\Component;
use illusiard\rabbitmq\amqp\AmqpConnection;
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
use illusiard\rabbitmq\middleware\PublishPipeline;
use illusiard\rabbitmq\middleware\PublishMiddlewareInterface;
use illusiard\rabbitmq\contracts\ReturnHandlerInterface;
use illusiard\rabbitmq\amqp\LoggingReturnHandler;
use illusiard\rabbitmq\amqp\InMemoryReturnSink;
use illusiard\rabbitmq\amqp\ReturnSinkInterface;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\definitions\discovery\DiscoveryConfig;
use illusiard\rabbitmq\definitions\discovery\DefinitionsDiscovery;
use illusiard\rabbitmq\definitions\registry\ConsumerRegistry as DefinitionConsumerRegistry;
use illusiard\rabbitmq\definitions\registry\PublisherRegistry as DefinitionPublisherRegistry;
use illusiard\rabbitmq\definitions\registry\MiddlewareRegistry as DefinitionMiddlewareRegistry;
use illusiard\rabbitmq\definitions\registry\HandlerRegistry as DefinitionHandlerRegistry;
use illusiard\rabbitmq\definitions\consumer\ConsumerInterface as DefinitionConsumerInterface;
use illusiard\rabbitmq\definitions\publisher\PublisherInterface as DefinitionPublisherInterface;
use illusiard\rabbitmq\profile\OptionsMerger;
use illusiard\rabbitmq\profile\ProfiledConsumer;
use illusiard\rabbitmq\profile\ProfiledPublisher;
use illusiard\rabbitmq\profile\RabbitMqProfileInterface;
use InvalidArgumentException;
use illusiard\rabbitmq\topology\Topology;
use illusiard\rabbitmq\topology\TopologyBuilder;
use illusiard\rabbitmq\topology\TopologyApplier;
use yii\base\InvalidConfigException;

/**
 *
 * @property-read DiscoveryConfig $discoveryConfigOrThrow
 * @property-write array          $upTopology
 * @property-read array           $connectionConfig
 */
class RabbitMqService extends Component
{
    public string       $host                        = '127.0.0.1';
    public int          $port                        = 5672;
    public string       $user                        = 'guest';
    public string       $password                    = 'guest';
    public string       $vhost                       = '/';
    public int          $heartbeat                   = 30;
    public int          $readWriteTimeout            = 3;
    public int          $connectionTimeout           = 3;
    public ?array       $ssl                         = null;
    public array        $topology                    = [];
    public bool         $confirm                     = false;
    public bool         $mandatory                   = false;
    public bool         $mandatoryStrict             = true;
    public int          $publishTimeout              = 5;
    public bool         $managedRetry                = false;
    public array        $retryPolicy                 = [];
    public array        $profiles                    = [];
    public string       $defaultProfile              = 'default';
    public array|string $serializer                  = JsonMessageSerializer::class;
    public array        $publishMiddlewares          = [];
    public array        $consumeMiddlewares          = [];
    public bool         $consumeFailFast             = true;
    public array        $fatalExceptionClasses       = [];
    public array        $recoverableExceptionClasses = [];
    public array|string $returnHandler               = LoggingReturnHandler::class;
    public bool         $returnHandlerEnabled        = true;
    public array|string $returnSink                  = InMemoryReturnSink::class;
    public bool         $returnSinkEnabled           = true;
    public ?string      $componentId                 = null;
    public array        $discovery                   = [];
    public mixed        $profile                     = null;

    /** @var callable|null */
    public $connectionFactory;

    private ?ConnectionInterface          $connection            = null;
    private ?PublisherInterface           $publisher             = null;
    private ?MessageSerializerInterface   $serializerInstance    = null;
    private ?PublishPipeline              $publishPipeline       = null;
    private ?ReturnHandlerInterface       $returnHandlerInstance = null;
    private ?ReturnSinkInterface          $returnSinkInstance    = null;
    private ?string                       $activeProfile         = null;
    private ?string                       $lastError             = null;
    private ?DefinitionConsumerRegistry   $consumerRegistry      = null;
    private ?DefinitionPublisherRegistry  $publisherRegistry     = null;
    private ?DefinitionMiddlewareRegistry $middlewareRegistry    = null;
    private ?DefinitionHandlerRegistry    $handlerRegistry       = null;
    private ?RabbitMqProfileInterface     $profileInstance       = null;

    public function init(): void
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
                    'mandatoryStrict'   => $this->mandatoryStrict,
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
                'returnSink'                  => $this->returnSink,
                'returnSinkEnabled'           => $this->returnSinkEnabled,
            ];

            if (!empty($this->profiles)) {
                $config['profiles']       = $this->profiles;
                $config['defaultProfile'] = $this->defaultProfile;
            }

            $validator->validate($config);
        } catch (RabbitMqException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RabbitMqException(
                'Config validation failed: ' . $e->getMessage(),
                ErrorCode::CONFIG_INVALID,
                0,
                $e
            );
        }
    }

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routingKey
     * @param array  $properties
     * @param array  $headers
     *
     * @return void
     */
    public function publish(
        string $body,
        string $exchange = '',
        string $routingKey = '',
        array $properties = [],
        array $headers = []
    ): void {
        try {
            $this->getPublisher()->publish($body, $exchange, $routingKey, $properties, $headers);
        } catch (ConnectionException|PublishException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new PublishException('Publish failed: ' . $e->getMessage(), ErrorCode::PUBLISH_FAILED, 0, $e);
        }
    }

    public function forProfile(string $name): self
    {
        $clone                = clone $this;
        $clone->activeProfile = $name;

        return $clone;
    }

    /**
     * @param Envelope $env
     * @param string   $exchange
     * @param string   $routingKey
     *
     * @return void
     * @throws InvalidConfigException
     */
    public function publishEnvelope(Envelope $env, string $exchange = '', string $routingKey = ''): void
    {
        $context = $this->buildPublishContext($exchange, $routingKey);

        $this->getPublishPipeline()->run($env, $context, function (Envelope $env) use ($exchange, $routingKey) {
            $serializer = $this->getSerializer();
            $body       = $serializer->encode($env);

            $properties = $env->getProperties();
            unset($properties['application_headers']);
            $properties['content_type'] = $serializer->getContentType();
            $properties['message_id'] = $env->getMessageId();
            if ($env->getCorrelationId() !== null) {
                $properties['correlation_id'] = $env->getCorrelationId();
            }

            $this->publish($body, $exchange, $routingKey, $properties, $env->getHeaders());
        });
    }

    /**
     * @param string $body
     * @param string $exchange
     * @param string $routingKey
     * @param array  $properties
     * @param array  $headers
     *
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @param mixed  $payload
     * @param string $exchange
     * @param string $routingKey
     * @param array  $options
     *
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @param string $body
     * @param array  $meta
     *
     * @return Envelope
     * @throws InvalidConfigException
     */
    public function decodeEnvelope(string $body, array $meta = []): Envelope
    {
        return $this->getSerializer()->decode($body, $meta);
    }

    /**
     * @return ConnectionInterface
     * @throws InvalidConfigException
     */
    public function getConnection(): ConnectionInterface
    {
        if ($this->connection === null) {
            $this->connection = $this->createConnection();
        }

        return $this->connection;
    }

    /**
     * @return PublisherInterface
     * @throws InvalidConfigException
     */
    public function getPublisher(): PublisherInterface
    {
        if ($this->publisher === null) {
            $this->publisher = $this->ensureConnection()->getPublisher();
        }

        return $this->publisher;
    }

    /**
     * @param string $queue
     * @param        $handler
     * @param array  $options
     *
     * @return void
     * @throws Throwable
     * @throws InvalidConfigException
     */
    public function consume(string $queue, $handler, array $options = []): void
    {
        $exitCode = $this->createRunner()->run($queue, $handler, $options);
        if ($exitCode !== 0) {
            throw new RabbitMqException('Consume failed.', ErrorCode::CONSUME_FAILED);
        }
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

        $config                 = $this->getDiscoveryConfigOrThrow();
        $discovery              = new DefinitionsDiscovery($config);
        $this->consumerRegistry = $discovery->discoverConsumers();

        return $this->consumerRegistry;
    }

    public function getPublisherRegistry(): DefinitionPublisherRegistry
    {
        if ($this->publisherRegistry !== null) {
            return $this->publisherRegistry;
        }

        $config                  = $this->getDiscoveryConfigOrThrow();
        $discovery               = new DefinitionsDiscovery($config);
        $this->publisherRegistry = $discovery->discoverPublishers();

        return $this->publisherRegistry;
    }

    public function getMiddlewareRegistry(): DefinitionMiddlewareRegistry
    {
        if ($this->middlewareRegistry !== null) {
            return $this->middlewareRegistry;
        }

        $config                   = $this->getDiscoveryConfigOrThrow();
        $discovery                = new DefinitionsDiscovery($config);
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

        $discovery             = new DefinitionsDiscovery($config);
        $this->handlerRegistry = $discovery->discoverHandlers();

        return $this->handlerRegistry;
    }

    /**
     * @param string $fqcn
     *
     * @return DefinitionConsumerInterface
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    public function createConsumerDefinition(string $fqcn): DefinitionConsumerInterface
    {
        $instance = Yii::createObject($fqcn);
        if (!$instance instanceof DefinitionConsumerInterface) {
            throw new InvalidArgumentException("Consumer class '$fqcn' must implement definitions ConsumerInterface.");
        }

        return $this->applyProfileToConsumer($instance);
    }

    /**
     * @param string $fqcn
     *
     * @return DefinitionPublisherInterface
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    public function createPublisherDefinition(string $fqcn): DefinitionPublisherInterface
    {
        $instance = Yii::createObject($fqcn);
        if (!$instance instanceof DefinitionPublisherInterface) {
            throw new InvalidArgumentException(
                "Publisher class '$fqcn' must implement definitions PublisherInterface."
            );
        }

        return $this->applyProfileToPublisher($instance);
    }

    /**
     * @return RabbitMqProfileInterface|null
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    public function getProfile(): ?RabbitMqProfileInterface
    {
        if ($this->profileInstance !== null) {
            return $this->profileInstance;
        }

        if ($this->profile === null) {
            return null;
        }

        $profile = $this->profile;
        if (is_string($profile)) {
            $profile = Yii::createObject($profile);
        } elseif (is_callable($profile)) {
            $profile = $this->invokeProfileFactory($profile);
        } elseif (!is_object($profile)) {
            throw new InvalidArgumentException('profile must be null, a FQCN, a callable, or an object.');
        }

        if (!$profile instanceof RabbitMqProfileInterface) {
            throw new InvalidArgumentException('profile must implement RabbitMqProfileInterface.');
        }

        $this->profileInstance = $profile;

        return $this->profileInstance;
    }

    /**
     * @param DefinitionConsumerInterface $consumer
     *
     * @return DefinitionConsumerInterface
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    private function applyProfileToConsumer(DefinitionConsumerInterface $consumer): DefinitionConsumerInterface
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return $consumer;
        }

        $options     = $consumer->getOptions();
        $middlewares = $consumer->getMiddlewares();

        $mergedOptions     = $this->mergeProfileOptions($profile, $profile->getConsumerDefaults(), $options);
        $mergedMiddlewares = $this->mergeProfileOptions(
            $profile,
            $this->getProfileConsumerMiddlewareDefaults($profile),
            $middlewares
        );

        if ($options === $mergedOptions && $middlewares === $mergedMiddlewares) {
            return $consumer;
        }

        return new ProfiledConsumer($consumer, $mergedOptions, $mergedMiddlewares);
    }

    /**
     * @param DefinitionPublisherInterface $publisher
     *
     * @return DefinitionPublisherInterface
     * @throws InvalidConfigException
     * @throws ReflectionException
     */
    private function applyProfileToPublisher(DefinitionPublisherInterface $publisher): DefinitionPublisherInterface
    {
        $profile = $this->getProfile();
        if ($profile === null) {
            return $publisher;
        }

        $options     = $publisher->getOptions();
        $middlewares = $publisher->getMiddlewares();

        $mergedOptions     = $this->mergeProfileOptions($profile, $profile->getPublisherDefaults(), $options);
        $mergedMiddlewares = $this->mergeProfileOptions(
            $profile,
            $this->getProfilePublisherMiddlewareDefaults($profile),
            $middlewares
        );

        if ($options === $mergedOptions && $middlewares === $mergedMiddlewares) {
            return $publisher;
        }

        return new ProfiledPublisher($publisher, $mergedOptions, $mergedMiddlewares);
    }

    private function mergeProfileOptions(
        RabbitMqProfileInterface $profile,
        array $defaults,
        array $overrides
    ): array {
        if (method_exists($profile, 'mergeOptions')) {
            return $profile->mergeOptions($defaults, $overrides);
        }

        return OptionsMerger::merge($defaults, $overrides);
    }

    private function getProfileConsumerMiddlewareDefaults(RabbitMqProfileInterface $profile): array
    {
        $defaults = $this->getProfileMiddlewareDefaults($profile);

        if (isset($defaults['consumer']) && is_array($defaults['consumer'])) {
            return $defaults['consumer'];
        }

        if (isset($defaults['consume']) && is_array($defaults['consume'])) {
            return $defaults['consume'];
        }

        return [];
    }

    private function getProfilePublisherMiddlewareDefaults(RabbitMqProfileInterface $profile): array
    {
        $defaults = $this->getProfileMiddlewareDefaults($profile);

        if (isset($defaults['publisher']) && is_array($defaults['publisher'])) {
            return $defaults['publisher'];
        }

        if (isset($defaults['publish']) && is_array($defaults['publish'])) {
            return $defaults['publish'];
        }

        return [];
    }

    private function getProfileMiddlewareDefaults(RabbitMqProfileInterface $profile): array
    {
        if (!method_exists($profile, 'getMiddlewareDefaults')) {
            return [];
        }

        return $profile->getMiddlewareDefaults();
    }

    /**
     * @param callable $factory
     *
     * @return object
     * @throws ReflectionException
     */
    private function invokeProfileFactory(callable $factory): object
    {
        $closure    = $factory(...);
        $reflection = new ReflectionFunction($closure);

        if ($reflection->getNumberOfParameters() > 0) {
            return $closure($this);
        }

        return $closure();
    }

    /**
     * @param float $timeout
     *
     * @return void
     * @throws InvalidConfigException
     */
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

    /**
     * @return array
     * @throws InvalidConfigException
     */
    public function drainReturns(): array
    {
        $publisher = $this->getPublisher();
        if (method_exists($publisher, 'drainReturns')) {
            return $publisher->drainReturns();
        }

        $sink = $this->getReturnSink();
        if ($sink !== null && method_exists($sink, 'drainReturns')) {
            return $sink->drainReturns();
        }

        return [];
    }

    /**
     * @param array $config
     * @param bool $dryRun
     * @return void
     * @throws InvalidConfigException
     * @throws JsonException
     */
    public function setupTopology(array $config, bool $dryRun = false): void
    {
        $topology = (new TopologyBuilder())->buildFromConfig($config);
        $topology->validate();
        if ($dryRun) {
            return;
        }

        $this->applyTopology($topology);
    }

    /**
     * @return Topology
     * @throws InvalidConfigException
     * @throws JsonException
     * @throws ReflectionException
     */
    public function buildTopology(): Topology
    {
        return (new TopologyBuilder())->buildFromService($this);
    }

    /**
     * @param Topology $topology
     * @param bool $dryRun
     *
     * @return void
     * @throws InvalidConfigException
     * @throws Exception
     */
    public function applyTopology(Topology $topology, bool $dryRun = false): void
    {
        $topology->validate();

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
                if (method_exists($amqp, 'channel')) {
                    $channel = $amqp->channel();
                    if (is_object($channel) && method_exists($channel, 'close')) {
                        $channel->close();
                    }
                }
            }

            return true;
        } catch (Throwable $e) {
            $this->lastError = $this->formatError($e);

            return false;
        } finally {
            if ($connection instanceof ConnectionInterface) {
                try {
                    $connection->close();
                } catch (Throwable) {
                }
            }
        }
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    /**
     * @return ConnectionInterface
     * @throws InvalidConfigException
     */
    private function ensureConnection(): ConnectionInterface
    {
        $connection = $this->getConnection();

        if (!$connection->isConnected()) {
            try {
                $connection->connect();
            } catch (Throwable $e) {
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

    /**
     * @return ConnectionInterface
     * @throws InvalidConfigException
     */
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

    private function formatError(Throwable $e): string
    {
        $code = $e instanceof RabbitMqException ? $e->getErrorCode() : ErrorCode::GENERIC;

        return $code . ' ' . get_class($e) . ': ' . $e->getMessage();
    }

    /**
     * @return MessageSerializerInterface
     * @throws InvalidConfigException
     */
    public function getSerializer(): MessageSerializerInterface
    {
        if ($this->serializerInstance !== null) {
            return $this->serializerInstance;
        }

        if (is_string($this->serializer)) {
            $this->serializer = ['class' => $this->serializer];
        }

        $serializer = Yii::createObject($this->serializer);

        if ($serializer instanceof MessageSerializerInterface) {
            return $this->serializerInstance = $serializer;
        }

        throw new InvalidConfigException('Serializer must implement MessageSerializerInterface.');
    }

    /**
     * @return ReturnHandlerInterface|null
     * @throws InvalidConfigException
     */
    private function getReturnHandler(): ?ReturnHandlerInterface
    {
        if (!$this->returnHandlerEnabled) {
            return null;
        }

        if ($this->returnHandlerInstance !== null) {
            return $this->returnHandlerInstance;
        }

        if (is_string($this->returnHandler)) {
            $this->returnHandler = ['class' => $this->returnHandler];
        }

        $returnHandler = Yii::createObject($this->returnHandler);

        if ($returnHandler instanceof ReturnHandlerInterface) {
            return $this->returnHandlerInstance = $returnHandler;
        }

        throw new InvalidArgumentException('ReturnHandler must implement ReturnHandlerInterface.');
    }

    /**
     * @return ReturnSinkInterface|null
     * @throws InvalidConfigException
     */
    public function getReturnSink(): ?ReturnSinkInterface
    {
        if (!$this->returnSinkEnabled) {
            return null;
        }

        if ($this->returnSinkInstance !== null) {
            return $this->returnSinkInstance;
        }

        if (is_string($this->returnSink)) {
            $this->returnSink = ['class' => $this->returnSink];
        }

        $returnSink = Yii::createObject($this->returnSink);

        if ($returnSink instanceof ReturnSinkInterface) {
            return $this->returnSinkInstance = $returnSink;
        }

        throw new InvalidArgumentException('ReturnSink must implement ReturnSinkInterface.');
    }

    /**
     * @return PublishPipeline
     * @throws InvalidConfigException
     */
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

    /**
     * @param array  $configs
     * @param class-string $interface
     *
     * @return array
     * @throws InvalidConfigException
     */
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

    /**
     * @return array
     * @throws InvalidConfigException
     */
    private function getConnectionConfig(): array
    {
        $base = [
            'host'                 => $this->host,
            'port'                 => $this->port,
            'user'                 => $this->user,
            'password'             => $this->password,
            'vhost'                => $this->vhost,
            'heartbeat'            => $this->heartbeat,
            'readWriteTimeout'     => $this->readWriteTimeout,
            'connectionTimeout'    => $this->connectionTimeout,
            'ssl'                  => $this->ssl,
            'confirm'              => $this->confirm,
            'mandatory'            => $this->mandatory,
            'mandatoryStrict'      => $this->mandatoryStrict,
            'publishTimeout'       => $this->publishTimeout,
            'returnHandler'        => $this->getReturnHandler(),
            'returnHandlerEnabled' => $this->returnHandlerEnabled,
            'returnSink'           => $this->getReturnSink(),
            'returnSinkEnabled'    => $this->returnSinkEnabled,
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
        $this->connection            = null;
        $this->publisher             = null;
        $this->serializerInstance    = null;
        $this->publishPipeline       = null;
        $this->returnHandlerInstance = null;
        $this->returnSinkInstance    = null;
        $this->lastError             = null;
        $this->consumerRegistry      = null;
        $this->publisherRegistry     = null;
        $this->middlewareRegistry    = null;
        $this->handlerRegistry       = null;
        $this->profileInstance       = null;
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
