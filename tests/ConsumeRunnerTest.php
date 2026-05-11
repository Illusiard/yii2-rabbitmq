<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\contracts\ConnectionInterface;
use illusiard\rabbitmq\contracts\ConsumerInterface;
use illusiard\rabbitmq\contracts\PublisherInterface;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\middleware\MiddlewareInterface;
use RuntimeException;
use Yii;
use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use PHPUnit\Framework\TestCase;

class ConsumeRunnerTest extends TestCase
{
    private ?string $originalRuntimeAlias = null;

    protected function setUp(): void
    {
        parent::setUp();
        $runtime = Yii::getAlias('@runtime', false);
        $this->originalRuntimeAlias = is_string($runtime) ? $runtime : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalRuntimeAlias !== null) {
            Yii::setAlias('@runtime', $this->originalRuntimeAlias);
        }

        parent::tearDown();
    }

    public function testRunCreatesAndRemovesLockFileOnFailure(): void
    {
        $lockFile = sys_get_temp_dir() . '/rabbitmq_runner_' . uniqid('', true) . '.lock';
        @unlink($lockFile);

        $service = new class ($lockFile) extends RabbitMqService {
            public string $expectedLockFile;

            public function __construct(string $lockFile)
            {
                $this->expectedLockFile = $lockFile;
                parent::__construct();
            }

            public function getConnection(): ConnectionInterface
            {
                $lockFile = $this->expectedLockFile;
                return new class ($lockFile) implements ConnectionInterface {
                    private string $lockFile;

                    public function __construct(string $lockFile)
                    {
                        $this->lockFile = $lockFile;
                    }

                    public function connect(): void
                    {
                    }

                    public function isConnected(): bool
                    {
                        return false;
                    }

                    public function close(): void
                    {
                    }

                    public function getPublisher(): PublisherInterface
                    {
                        throw new RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): ConsumerInterface
                    {
                        $lockFile = $this->lockFile;
                        return new class ($lockFile) implements ConsumerInterface {
                            private string $lockFile;

                            public function __construct(string $lockFile)
                            {
                                $this->lockFile = $lockFile;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                if (!is_file($this->lockFile)) {
                                    throw new RuntimeException('Lock file missing.');
                                }

                                throw new RuntimeException('Forced failure.');
                            }
                        };
                    }
                };
            }
        };

        $runner = new ConsumeRunner($service);
        $options = new RunnerOptions($lockFile, null, 'consumer-id');

        $exitCode = $runner->run('queue', function (): bool {
            return true;
        }, [], $options);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($lockFile);
    }

    public function testRunUsesDefaultRuntimeLockFile(): void
    {
        $runtime = sys_get_temp_dir() . '/rabbitmq_runtime_' . uniqid('', true);
        Yii::setAlias('@runtime', $runtime);
        $lockFile = $runtime . '/rabbitmq/consumer-id.lock';

        $service = new class ($lockFile) extends RabbitMqService {
            public string $expectedLockFile;

            public function __construct(string $lockFile)
            {
                $this->expectedLockFile = $lockFile;
                parent::__construct();
            }

            public function getConnection(): ConnectionInterface
            {
                $lockFile = $this->expectedLockFile;
                return new class ($lockFile) implements ConnectionInterface {
                    private string $lockFile;

                    public function __construct(string $lockFile)
                    {
                        $this->lockFile = $lockFile;
                    }

                    public function connect(): void
                    {
                    }

                    public function isConnected(): bool
                    {
                        return false;
                    }

                    public function close(): void
                    {
                    }

                    public function getPublisher(): PublisherInterface
                    {
                        throw new RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): ConsumerInterface
                    {
                        $lockFile = $this->lockFile;
                        return new class ($lockFile) implements ConsumerInterface {
                            private string $lockFile;

                            public function __construct(string $lockFile)
                            {
                                $this->lockFile = $lockFile;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                if (!is_file($this->lockFile)) {
                                    throw new RuntimeException('Default lock file missing.');
                                }

                                throw new RuntimeException('Forced failure.');
                            }
                        };
                    }
                };
            }
        };

        $runner = new ConsumeRunner($service);
        $options = new RunnerOptions(null, null, 'consumer-id');

        $exitCode = $runner->run('queue', function (): bool {
            return true;
        }, [], $options);

        $this->assertSame(1, $exitCode);
        $this->assertFileDoesNotExist($lockFile);
    }

    public function testStringHandlerMustImplementHandlerInterface(): void
    {
        $service = new class extends RabbitMqService {
            public bool $consumeCalled = false;

            public function getConnection(): ConnectionInterface
            {
                $service = $this;

                return new class ($service) implements ConnectionInterface {
                    private RabbitMqService $service;

                    public function __construct(RabbitMqService $service)
                    {
                        $this->service = $service;
                    }

                    public function connect(): void
                    {
                    }

                    public function isConnected(): bool
                    {
                        return false;
                    }

                    public function close(): void
                    {
                    }

                    public function getPublisher(): PublisherInterface
                    {
                        throw new RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): ConsumerInterface
                    {
                        $service = $this->service;

                        return new class ($service) implements ConsumerInterface {
                            private RabbitMqService $service;

                            public function __construct(RabbitMqService $service)
                            {
                                $this->service = $service;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                $this->service->consumeCalled = true;
                            }
                        };
                    }
                };
            }
        };

        $runner = new ConsumeRunner($service);
        $exitCode = $runner->run('queue', NonHandlerInvokable::class);

        $this->assertSame(1, $exitCode);
        $this->assertFalse($service->consumeCalled);
    }

    public function testMiddlewareOrderIsSystemBeforeUserSystemAfter(): void
    {
        TestRunnerUserMiddleware::$actions = [];

        $service = new class extends RabbitMqService {
            public function getConnection(): ConnectionInterface
            {
                return new class implements ConnectionInterface {
                    public function connect(): void
                    {
                    }

                    public function isConnected(): bool
                    {
                        return false;
                    }

                    public function close(): void
                    {
                    }

                    public function getPublisher(): PublisherInterface
                    {
                        throw new RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): ConsumerInterface
                    {
                        return new class implements ConsumerInterface {
                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                $handler('{"ok":true}', [
                                    'headers' => [],
                                    'properties' => [],
                                    'delivery_tag' => 1,
                                    'routing_key' => 'routing',
                                    'exchange' => 'exchange',
                                    'redelivered' => false,
                                ]);
                            }
                        };
                    }
                };
            }
        };

        $runner = new ConsumeRunner($service);
        $exitCode = $runner->run('queue', function (): ConsumeResult {
            TestRunnerUserMiddleware::$actions[] = 'handler';
            return ConsumeResult::retry();
        }, [
            'managedRetry' => false,
            'consumeMiddlewares' => [
                TestRunnerUserMiddleware::class,
            ],
        ]);

        $this->assertSame(0, $exitCode);
        $this->assertSame([
            'user-before',
            'handler',
            'user-after-reject',
        ], TestRunnerUserMiddleware::$actions);
    }
}

class NonHandlerInvokable
{
    public function __invoke(): bool
    {
        return true;
    }
}

class TestRunnerUserMiddleware implements MiddlewareInterface
{
    public static array $actions = [];

    public function process(ConsumeContext $context, callable $next): ConsumeResult
    {
        self::$actions[] = 'user-before';
        $result = $next($context);
        $normalized = ConsumeResult::normalizeHandlerResult($result);
        self::$actions[] = 'user-after-' . $normalized->getAction();

        return $normalized;
    }
}
