<?php

namespace illusiard\rabbitmq\tests\helpers;

use RuntimeException;

class ProcessHelper
{
    public static function startProcess(array $cmd, array $env, array $descriptors): array
    {
        $process = proc_open($cmd, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw new RuntimeException('Failed to start process.');
        }

        return [$process, $pipes];
    }

    public static function terminateProcess($process): void
    {
        if (!is_resource($process)) {
            return;
        }

        proc_terminate($process);
        proc_close($process);
    }

    public static function waitForProcessExit($process, int $timeoutSec): int
    {
        if (!is_resource($process)) {
            return -1;
        }

        $status = proc_get_status($process);
        $pid = (int)($status['pid'] ?? 0);
        if ($pid <= 0) {
            proc_close($process);
            return -1;
        }

        $deadline = microtime(true) + max(0, $timeoutSec);

        if (function_exists('pcntl_waitpid')) {
            while (microtime(true) < $deadline) {
                $wait = pcntl_waitpid($pid, $wstatus, WNOHANG);
                if ($wait === -1) {
                    break;
                }
                if ($wait > 0) {
                    proc_close($process);

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
        } else {
            while (microtime(true) < $deadline) {
                $status = proc_get_status($process);
                if (!$status['running']) {
                    $code = $status['exitcode'] ?? -1;
                    proc_close($process);
                    return is_int($code) ? $code : -1;
                }
                usleep(100000);
            }
        }

        proc_terminate($process);
        $status = proc_get_status($process);
        $code = $status['exitcode'] ?? -1;
        proc_close($process);

        return is_int($code) ? $code : -1;
    }

    public static function closePipes(array $pipes): void
    {
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
    }
}
