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
    public ?string $componentId                 = null;

    /** @var callable|null */
    public $connectionFactory;

    private ?ConnectionInterface        $connection         = null;
    private ?PublisherInterface         $publisher          = null;
    private ?MessageSerializerInterface $serializerInstance = null;
    private ?PublishPipeline            $publishPipeline    = null;
    private ?ConsumePipeline            $consumePipeline    = null;
    private ?string                     $activeProfile      = null;
    private ?string                     $lastError          = null;

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

    public function consume(string $queue, string $handlerFqcn, int $prefetch = 1): void
    {
        $handler = Yii::createObject($handlerFqcn);
        if (!is_callable($handler)) {
            throw new \InvalidArgumentException('Handler must be callable via __invoke.');
        }

        $context        = $this->buildConsumeContext($queue, $handlerFqcn);
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

    public function setupTopology(array $config): void
    {
        $connection = $this->ensureConnection();
        if (!$connection instanceof AmqpConnection) {
            throw new ConnectionException('Topology setup requires AmqpConnection.', ErrorCode::CONNECTION_FAILED);
        }

        $options = $config['options'] ?? [];
        $manager = new TopologyManager($connection, $options);
        $manager->apply($config, $options);
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
                throw new \InvalidArgumentException('Middleware must implement ' . $interface);
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
        $this->lastError          = null;
    }
}
