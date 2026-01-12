<?php

namespace illusiard\rabbitmq\tests\integration;

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
            'CONSUMER_READY_FILE' => $readyFile,
        ]);

        $null = (stripos(PHP_OS_FAMILY, 'Windows') !== false) ? 'NUL' : '/dev/null';
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['file', $null, 'w'],
            2 => ['file', $null, 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        $this->assertIsResource($process);

        fclose($pipes[0]);

        $ready = $this->waitForReadyFile($readyFile, 5);
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
        if ($readyFile !== '') {
            @unlink($readyFile);
        }
    }

    private function waitForReadyFile(string $path, int $timeoutSec): bool
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        while (microtime(true) < $deadline) {
            if (is_file($path)) {
                $data = @file_get_contents($path);
                if (is_string($data) && str_contains($data, 'READY')) {
                    return true;
                }
            }
            usleep(10_000); // 10ms
        }

        return false;
    }

    private function waitForProcessExit($process, array $pipes, int $timeoutSec): int
    {
        $deadline = microtime(true) + max(0, $timeoutSec);

        $status = proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        if ($pid <= 0) {
            $this->closePipes($pipes);
            if (is_resource($process)) {
                proc_terminate($process);
                proc_close($process);
            }
            return -1;
        }

        while (microtime(true) < $deadline) {
            $wait = pcntl_waitpid($pid, $wstatus, WNOHANG);
            if ($wait === -1) {
                break;
            }
            if ($wait > 0) {
                $this->closePipes($pipes);
                if (is_resource($process)) {
                    proc_close($process);
                }

                if (pcntl_wifexited($wstatus)) {
                    return pcntl_wexitstatus($wstatus);
                }

                if (pcntl_wifsignaled($wstatus)) {
                    return 128 + pcntl_wtermsig($wstatus);
                }

                return -1;
            }

            usleep(100000);
        }

        if (is_resource($process)) {
            proc_terminate($process);
        }
        $this->closePipes($pipes);

        $wait = pcntl_waitpid($pid, $wstatus, 0);
        if ($wait > 0) {
            if (pcntl_wifexited($wstatus)) {
                $code = pcntl_wexitstatus($wstatus);
            } elseif (pcntl_wifsignaled($wstatus)) {
                $code = 128 + pcntl_wtermsig($wstatus);
            } else {
                $code = -1;
            }
        } else {
            $code = -1;
        }

        if (is_resource($process)) {
            proc_close($process);
        }

        return $code;
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
