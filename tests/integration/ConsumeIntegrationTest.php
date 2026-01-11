<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\tests\integration\fixtures\ConsumeHandler;

/**
 * @group integration
 */
class ConsumeIntegrationTest extends IntegrationTestCase
{
    public function testAMQP_CONSUME_01_gracefulShutdown(): void
    {
        if (!function_exists('pcntl_signal') || !function_exists('posix_kill')) {
            $this->markTestSkipped('pcntl/posix extensions are required for SIGTERM tests.');
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('SIGTERM handling is not supported on Windows.');
        }

        $queue = $this->uniqueName('shutdown_q');
        $this->declareQueue($queue);

        $this->publishRaw('payload', '', $queue);

        $logFile = tempnam(sys_get_temp_dir(), 'consume_log_');
        if ($logFile === false) {
            $this->markTestSkipped('Failed to create temp log file.');
        }

        $cmd = [
            PHP_BINARY,
            __DIR__ . '/fixtures/consume_process.php',
        ];

        $env = array_merge(getenv(), [
            'CONSUME_QUEUE' => $queue,
            'CONSUME_HANDLER' => ConsumeHandler::class,
            'HANDLER_LOG' => $logFile,
            'HANDLER_SLEEP_MS' => '200',
        ]);

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);

        $ready = $this->waitForProcessOutput($pipes[1], 'READY', 5);
        $this->assertTrue($ready, 'Consumer process did not become ready.');

        $status = proc_get_status($process);
        $this->assertTrue($status['running']);

        posix_kill($status['pid'], SIGTERM);

        $exitCode = $this->waitForProcessExit($process, $pipes, 10);

        $this->assertSame(0, $exitCode);

        $lines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
        $this->assertCount(1, $lines);

        $this->assertTrue($this->waitForQueueCount($queue, 0));

        @unlink($logFile);
    }

    private function waitForProcessOutput($stream, string $needle, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        stream_set_blocking($stream, false);
        $buffer = '';

        while (microtime(true) < $deadline) {
            $chunk = stream_get_contents($stream);
            if ($chunk !== false && $chunk !== '') {
                $buffer .= $chunk;
                if (strpos($buffer, $needle) !== false) {
                    return true;
                }
            }
            usleep(100000);
        }

        return false;
    }

    private function waitForProcessExit($process, array $pipes, int $timeoutSec): int
    {
        $deadline = microtime(true) + max(0, $timeoutSec);
        while (microtime(true) < $deadline) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $this->closePipes($pipes);
                return (int)$status['exitcode'];
            }
            usleep(100000);
        }

        proc_terminate($process);
        $this->closePipes($pipes);

        return -1;
    }

    private function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
