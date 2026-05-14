<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\helpers\ProcessHelper;
use illusiard\rabbitmq\amqp\AmqpConnection;
use illusiard\rabbitmq\amqp\AmqpConsumer;
use illusiard\rabbitmq\consume\DefaultExceptionClassifier;
use illusiard\rabbitmq\consume\ExceptionHandlingMiddleware;
use illusiard\rabbitmq\consume\ManagedRetryPolicy;
use illusiard\rabbitmq\consume\RetryPolicyMiddleware;
use illusiard\rabbitmq\definitions\consume\ConsumeContext;
use illusiard\rabbitmq\definitions\consume\ConsumeResult;
use illusiard\rabbitmq\definitions\consume\MessageMeta;
use illusiard\rabbitmq\definitions\consumer\RuntimeConsumer;
use RuntimeException;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * @group integration
 */
class ConsumeIntegrationTest extends IntegrationTestCase
{
    /**
     * @return void
     * @throws InvalidConfigException
     * @throws Throwable
     */
    public function testAMQP_CONSUME_01_gracefulShutdown(): void
    {
        $readyFile = sys_get_temp_dir() . '/rpc_ready_' . uniqid('', true) . '.txt';
        @unlink($readyFile);

        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix extensions are required for SIGTERM tests.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('SIGTERM handling is not supported on Windows.');
        }

        $queue = $this->uniqueName('shutdown_q');
        $this->declareQueue($queue);

        $this->publishRaw('"payload"', '', $queue);

        $logFile = tempnam(sys_get_temp_dir(), 'consume_log_');
        if ($logFile === false) {
            $this->markTestSkipped('Failed to create temp log file.');
        }
        $stdoutFile = tempnam(sys_get_temp_dir(), 'consume_stdout_');
        $stderrFile = tempnam(sys_get_temp_dir(), 'consume_stderr_');
        if ($stdoutFile === false || $stderrFile === false) {
            $this->markTestSkipped('Failed to create temp process output files.');
        }

        $cmd = [
            PHP_BINARY,
            __DIR__ . '/fixtures/consume_process.php',
        ];

        $env = array_merge(getenv(), [
            'CONSUME_QUEUE' => $queue,
            'CONSUMER_ID' => 'consume',
            'HANDLER_LOG' => $logFile,
            'HANDLER_SLEEP_MS' => '200',
            'READY_LOCK' => $readyFile,
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $stdoutFile, 'w'],
            2 => ['file', $stderrFile, 'w'],
        ];

        [$process, $pipes] = ProcessHelper::startProcess($cmd, $env, $descriptors);
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $ready = $this->waitForConsumerReady($process, $readyFile, 20);
        if (!$ready) {
            $status = proc_get_status($process);
            ProcessHelper::terminateProcess($process);
            ProcessHelper::closePipes($pipes);
            $this->fail(
                'Consumer process did not become ready. '
                . $this->formatProcessDiagnostics($status, $stdoutFile, $stderrFile)
            );
        }

        $status = proc_get_status($process);
        $this->assertTrue($status['running']);

        $this->assertTrue($this->waitForLogLines($logFile, 1, 5), 'Consumer did not handle the queued message.');

        posix_kill($status['pid'], SIGTERM);

        $exitCode = ProcessHelper::waitForProcessExit($process, 10);
        ProcessHelper::closePipes($pipes);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($this->waitForFileMissing($readyFile, 5));

        $lines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
        $this->assertCount(1, $lines);

        $this->assertTrue($this->waitForQueueCount($queue, 0));

        @unlink($logFile);
        @unlink($stdoutFile);
        @unlink($stderrFile);
        if ($readyFile !== '') {
            @unlink($readyFile);
        }
    }

    private function waitForConsumerReady($process, string $readyFile, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            if (is_file($readyFile)) {
                return true;
            }

            $status = proc_get_status($process);
            if (!($status['running'] ?? false)) {
                return false;
            }

            usleep(50_000);
        }

        return is_file($readyFile);
    }

    private function formatProcessDiagnostics(array $status, string $stdoutFile, string $stderrFile): string
    {
        $stdout = is_file($stdoutFile) ? trim((string)file_get_contents($stdoutFile)) : '';
        $stderr = is_file($stderrFile) ? trim((string)file_get_contents($stderrFile)) : '';
        $exitCode = $status['exitcode'] ?? null;
        $running = ($status['running'] ?? false) ? 'yes' : 'no';

        return sprintf(
            'running=%s exitcode=%s stdout="%s" stderr="%s"',
            $running,
            is_int($exitCode) ? (string)$exitCode : 'unknown',
            $stdout,
            $stderr
        );
    }

    private function waitForLogLines(string $path, int $expected, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            $lines = file_exists($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
            if (is_array($lines) && count($lines) >= $expected) {
                return true;
            }
            usleep(10_000);
        }

        return false;
    }

    /**
     * @return void
     * @throws Throwable
     */
    public function testAMQP_CONSUME_02_fatalExceptionStops(): void
    {
        $queue = $this->uniqueName('fatal_q');
        $this->declareQueue($queue);
        $this->publishRaw('"payload"', '', $queue);

        $connection = new AmqpConnection($this->getRabbitConfig());
        $consumer = new AmqpConsumer($connection);

        $handler = function (): bool {
            throw new RuntimeException('fatal');
        };

        $pipelineHandler = $this->buildPipelineHandler($queue, $handler, [
            'consumeFailFast' => true,
        ]);

        $consumer->consume($queue, $pipelineHandler);

        $this->assertTrue($this->waitForQueueCount($queue, 0));
    }

    /**
     * @param string $queue
     * @param callable $handler
     * @param array $options
     * @return callable
     * @throws InvalidConfigException
     */
    private function buildPipelineHandler(string $queue, callable $handler, array $options): callable
    {
        $consumerDef = new RuntimeConsumer($queue, $handler, $options);

        $classifier = new DefaultExceptionClassifier((bool)($options['consumeFailFast'] ?? true));
        $retryPolicy = new ManagedRetryPolicy($this->service, $options);
        $retryPolicy->validate();
        $retryMiddleware = new RetryPolicyMiddleware($retryPolicy);
        $exceptionMiddleware = new ExceptionHandlingMiddleware(
            $classifier,
            static fn(ConsumeResult $result, ConsumeContext $context): ConsumeResult => $retryMiddleware->process(
                $context,
                static fn(): ConsumeResult => $result
            )
        );

        $core = static function (ConsumeContext $context) use ($handler): ConsumeResult {
            $meta = $context->getMeta();
            $metaArray = [
                'body' => $meta->getBody(),
                'delivery_tag' => $meta->getDeliveryTag(),
                'routing_key' => $meta->getRoutingKey(),
                'exchange' => $meta->getExchange(),
                'redelivered' => $meta->isRedelivered(),
                'headers' => $meta->getHeaders(),
                'properties' => $meta->getProperties(),
            ];

            $result = $handler((string)$meta->getBody(), $metaArray);
            return ConsumeResult::normalizeHandlerResult($result);
        };

        $pipeline = static function (ConsumeContext $context) use ($exceptionMiddleware, $retryMiddleware, $core): ConsumeResult {
            $next = fn(ConsumeContext $context): ConsumeResult => $retryMiddleware->process($context, $core);

            return $exceptionMiddleware->process($context, $next);
        };

        return function (string $body, array $meta) use ($pipeline, $consumerDef): ConsumeResult {
            $headers = isset($meta['headers']) && is_array($meta['headers']) ? $meta['headers'] : [];
            $properties = isset($meta['properties']) && is_array($meta['properties']) ? $meta['properties'] : [];
            $deliveryTag = isset($meta['delivery_tag']) && is_int($meta['delivery_tag']) ? $meta['delivery_tag'] : null;
            $routingKey = isset($meta['routing_key']) && is_string($meta['routing_key']) ? $meta['routing_key'] : null;
            $exchange = isset($meta['exchange']) && is_string($meta['exchange']) ? $meta['exchange'] : null;
            $redelivered = isset($meta['redelivered']) && $meta['redelivered'];

            $messageMeta = new MessageMeta($headers, $properties, $body, $deliveryTag, $routingKey, $exchange, $redelivered);
            $envelope = $this->service->decodeEnvelope($body, $meta);
            $context = new ConsumeContext($envelope, $messageMeta, $this->service, $consumerDef);

            return $pipeline($context);
        };
    }
}
