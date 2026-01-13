<?php

namespace illusiard\rabbitmq\tests\integration;

use illusiard\rabbitmq\tests\helpers\ProcessHelper;

/**
 * @group integration
 */
class ConsumeIntegrationTest extends IntegrationTestCase
{
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
            'CONSUMER_ID' => 'consume',
            'HANDLER_LOG' => $logFile,
            'HANDLER_SLEEP_MS' => '200',
            'READY_LOCK' => $readyFile,
        ]);

        $null = (stripos(PHP_OS_FAMILY, 'Windows') !== false) ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];

        [$process, $pipes] = ProcessHelper::startProcess($cmd, $env, $descriptors);
        $this->assertIsResource($process);

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $ready = $this->waitForFileExists($readyFile, 5);
        $this->assertTrue($ready, 'Consumer process did not become ready.');

        $status = proc_get_status($process);
        $this->assertTrue($status['running']);

        posix_kill($status['pid'], SIGTERM);

        $exitCode = ProcessHelper::waitForProcessExit($process, 10);
        ProcessHelper::closePipes($pipes);

        $this->assertSame(0, $exitCode);
        $this->assertTrue($this->waitForFileMissing($readyFile, 5));

        $lines = file_exists($logFile) ? file($logFile, FILE_IGNORE_NEW_LINES) : [];
        $this->assertCount(1, $lines);

        $this->assertTrue($this->waitForQueueCount($queue, 0));

        @unlink($logFile);
        if ($readyFile !== '') {
            @unlink($readyFile);
        }
    }
}
