<?php

namespace illusiard\rabbitmq\tests;

use illusiard\rabbitmq\components\RabbitMqService;
use illusiard\rabbitmq\orchestration\ConsumeRunner;
use illusiard\rabbitmq\orchestration\RunnerOptions;
use PHPUnit\Framework\TestCase;

class ConsumeRunnerTest extends TestCase
{
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
}
