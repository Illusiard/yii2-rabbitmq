<?php

namespace illusiard\rabbitmq\tests;

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

            public function getConnection(): \illusiard\rabbitmq\contracts\ConnectionInterface
            {
                $lockFile = $this->expectedLockFile;
                return new class ($lockFile) implements \illusiard\rabbitmq\contracts\ConnectionInterface {
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

                    public function getPublisher(): \illusiard\rabbitmq\contracts\PublisherInterface
                    {
                        throw new \RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): \illusiard\rabbitmq\contracts\ConsumerInterface
                    {
                        $lockFile = $this->lockFile;
                        return new class ($lockFile) implements \illusiard\rabbitmq\contracts\ConsumerInterface {
                            private string $lockFile;

                            public function __construct(string $lockFile)
                            {
                                $this->lockFile = $lockFile;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                if (!is_file($this->lockFile)) {
                                    throw new \RuntimeException('Lock file missing.');
                                }

                                throw new \RuntimeException('Forced failure.');
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

            public function getConnection(): \illusiard\rabbitmq\contracts\ConnectionInterface
            {
                $lockFile = $this->expectedLockFile;
                return new class ($lockFile) implements \illusiard\rabbitmq\contracts\ConnectionInterface {
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

                    public function getPublisher(): \illusiard\rabbitmq\contracts\PublisherInterface
                    {
                        throw new \RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): \illusiard\rabbitmq\contracts\ConsumerInterface
                    {
                        $lockFile = $this->lockFile;
                        return new class ($lockFile) implements \illusiard\rabbitmq\contracts\ConsumerInterface {
                            private string $lockFile;

                            public function __construct(string $lockFile)
                            {
                                $this->lockFile = $lockFile;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                unset($queue, $handler, $prefetch);
                                if (!is_file($this->lockFile)) {
                                    throw new \RuntimeException('Default lock file missing.');
                                }

                                throw new \RuntimeException('Forced failure.');
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

            public function getConnection(): \illusiard\rabbitmq\contracts\ConnectionInterface
            {
                $service = $this;

                return new class ($service) implements \illusiard\rabbitmq\contracts\ConnectionInterface {
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

                    public function getPublisher(): \illusiard\rabbitmq\contracts\PublisherInterface
                    {
                        throw new \RuntimeException('Not used in tests.');
                    }

                    public function getConsumer(): \illusiard\rabbitmq\contracts\ConsumerInterface
                    {
                        $service = $this->service;

                        return new class ($service) implements \illusiard\rabbitmq\contracts\ConsumerInterface {
                            private RabbitMqService $service;

                            public function __construct(RabbitMqService $service)
                            {
                                $this->service = $service;
                            }

                            public function consume(string $queue, callable $handler, int $prefetch = 1): void
                            {
                                unset($queue, $handler, $prefetch);
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
}

class NonHandlerInvokable
{
    public function __invoke(): bool
    {
        return true;
    }
}
