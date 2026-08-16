<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

class DeploymentRunner
{
    private string $runtimeDir;

    public function __construct(string $runtimeDir)
    {
        $this->runtimeDir = $runtimeDir;
        ensureDirExists($this->runtimeDir . '/jobs');
        ensureDirExists($this->runtimeDir . '/pids');
        ensureDirExists($this->runtimeDir . '/streams');
    }

    public function getStreamPath(string $deploymentId): string
    {
        return $this->runtimeDir . '/streams/' . $deploymentId . '.log';
    }

    public function getJobPath(string $deploymentId): string
    {
        return $this->runtimeDir . '/jobs/' . $deploymentId . '.json';
    }

    public function getPidPath(string $deploymentId): string
    {
        return $this->runtimeDir . '/pids/' . $deploymentId . '.pid';
    }

    /**
     * Executes a deployment script as a non-blocking process.
     * Output (stdout + stderr) is streamed in real-time to runtime/streams/<deployment_id>.log.
     */
    public function startProcess(string $deploymentId, string $scriptPath, array $envVars = []): array
    {
        $streamFile = $this->getStreamPath($deploymentId);
        $jobFile = $this->getJobPath($deploymentId);
        $pidFile = $this->getPidPath($deploymentId);

        $workDir = is_dir(dirname($scriptPath)) ? dirname($scriptPath) : null;

        // Reset stream file with system execution details
        $initLog = "[" . date('H:i:s') . "] [SYSTEM] Initializing execution engine...\n" .
                   "[" . date('H:i:s') . "] [SYSTEM] Script Path: {$scriptPath}\n" .
                   "[" . date('H:i:s') . "] [SYSTEM] Working Dir: " . ($workDir ?: 'Default') . "\n";
        @file_put_contents($streamFile, $initLog);

        if (!file_exists($scriptPath)) {
            $errLog = "[" . date('H:i:s') . "] [ERROR] Script file does not exist at location: {$scriptPath}\n";
            @file_put_contents($streamFile, $errLog, FILE_APPEND);
            return [
                'success' => false,
                'pid' => 0,
                'error' => "Script file not found: {$scriptPath}"
            ];
        }

        $descriptors = [
            0 => ['pipe', 'r'], // stdin
            1 => ['file', $streamFile, 'a'], // stdout
            2 => ['file', $streamFile, 'a'], // stderr
        ];

        // Prepare environment
        $env = array_merge($_ENV, $envVars, [
            'DEPLOYMENT_ID' => $deploymentId,
            'PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
        ]);

        // Launch shell execution safely with absolute binary path
        $command = ['/bin/bash', $scriptPath];

        $process = proc_open($command, $descriptors, $pipes, $workDir, $env);

        if (!is_resource($process)) {
            @file_put_contents($streamFile, "[" . date('H:i:s') . "] [ERROR] Failed to spawn deployment process for script {$scriptPath}.\n", FILE_APPEND);
            return [
                'success' => false,
                'pid' => 0,
                'error' => 'Failed to spawn process resource.'
            ];
        }

        fclose($pipes[0]); // Close stdin

        $status = proc_get_status($process);
        $pid = (int)$status['pid'];

        @file_put_contents($pidFile, (string)$pid);

        $jobData = [
            'deployment_id' => $deploymentId,
            'pid' => $pid,
            'status' => 'running',
            'start_time' => time(),
            'script' => $scriptPath,
        ];
        safeWriteJson($jobFile, $jobData);

        return [
            'success' => true,
            'pid' => $pid,
            'process_resource' => $process,
            'error' => null
        ];
    }

    /**
     * Polls the status of an active deployment job.
     */
    public function pollJobStatus(string $deploymentId): array
    {
        $jobFile = $this->getJobPath($deploymentId);
        if (!file_exists($jobFile)) {
            return ['status' => 'unknown', 'running' => false, 'exit_code' => -1];
        }

        $job = safeReadJson($jobFile, []);
        $pid = (int)($job['pid'] ?? 0);

        if ($pid <= 0) {
            return ['status' => $job['status'] ?? 'unknown', 'running' => false, 'exit_code' => $job['exit_code'] ?? -1];
        }

        $isAlive = $this->isPidRunning($pid);

        if (!$isAlive && ($job['status'] ?? '') === 'running') {
            // Process finished - inspect stream log for exit errors
            $streamFile = $this->getStreamPath($deploymentId);
            $streamContent = file_exists($streamFile) ? file_get_contents($streamFile) : '';

            $hasExplicitDone = (strpos($streamContent, '[DONE]') !== false);
            $hasExplicitError = (strpos($streamContent, '[ERROR]') !== false ||
                                 strpos($streamContent, 'command not found') !== false ||
                                 strpos($streamContent, 'No such file or directory') !== false ||
                                 strpos($streamContent, 'Permission denied') !== false ||
                                 strpos($streamContent, 'syntax error') !== false);

            $exitCode = (int)($job['exit_code'] ?? ($hasExplicitError ? 1 : 0));

            if ($hasExplicitError || ($exitCode !== 0) || !$hasExplicitDone) {
                $newStatus = 'failed';
                if ($exitCode === 0 && !$hasExplicitDone) {
                    $exitCode = 1;
                    @file_put_contents($streamFile, "[" . date('H:i:s') . "] [ERROR] Deployment script exited unexpectedly before completion.\n", FILE_APPEND);
                }
            } else {
                $newStatus = 'script_completed';
            }

            $job['status'] = $newStatus;
            $job['exit_code'] = $exitCode;
            $job['running'] = false;
            $job['end_time'] = time();
            $job['duration'] = $job['end_time'] - $job['start_time'];

            safeWriteJson($jobFile, $job);
        }

        return $job;
    }

    /**
     * Terminate a running deployment process gracefully.
     */
    public function terminateProcess(string $deploymentId): bool
    {
        $pidFile = $this->getPidPath($deploymentId);
        if (!file_exists($pidFile)) {
            return false;
        }

        $pid = (int)trim(file_get_contents($pidFile));
        if ($pid <= 0) {
            return false;
        }

        $streamFile = $this->getStreamPath($deploymentId);
        @file_put_contents($streamFile, "[" . date('H:i:s') . "] [SYSTEM] Cancellation requested by administrator. Terminating process PID {$pid}...\n", FILE_APPEND);

        if ($this->isPidRunning($pid)) {
            // Send SIGTERM
            if (function_exists('posix_kill')) {
                @posix_kill($pid, SIGTERM);
                usleep(500000); // 0.5s
                if (@posix_kill($pid, 0)) {
                    @posix_kill($pid, SIGKILL);
                }
            } else {
                \safeExec("kill -15 $pid 2>/dev/null");
                usleep(500000);
                \safeExec("kill -9 $pid 2>/dev/null");
            }
        }

        $jobFile = $this->getJobPath($deploymentId);
        $job = safeReadJson($jobFile, []);
        $job['status'] = 'cancelled';
        $job['end_time'] = time();
        $job['duration'] = time() - ($job['start_time'] ?? time());
        $job['exit_code'] = 130;
        safeWriteJson($jobFile, $job);

        @unlink($pidFile);
        return true;
    }

    public function isPidRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $procStatFile = "/proc/$pid/status";
        if (file_exists($procStatFile)) {
            $content = @file_get_contents($procStatFile);
            if ($content !== false && preg_match('/State:\s+([Zz])/', $content)) {
                return false; // Zombie process = execution complete!
            }
            return true;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
