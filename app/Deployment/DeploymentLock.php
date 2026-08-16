<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

class DeploymentLock
{
    private string $locksDir;

    public function __construct(string $locksDir)
    {
        $this->locksDir = $locksDir;
        ensureDirExists($this->locksDir);
    }

    private function getLockFilePath(string $siteId): string
    {
        return $this->locksDir . '/' . $siteId . '.lock';
    }

    /**
     * Checks if site is locked. Automatically cleans stale locks if process is no longer active.
     */
    public function isLocked(string $siteId): bool
    {
        $file = $this->getLockFilePath($siteId);
        if (!file_exists($file)) {
            return false;
        }

        $info = safeReadJson($file, []);
        if (empty($info['pid'])) {
            @unlink($file);
            return false;
        }

        // Check if process with PID is still running using posix_kill(pid, 0) if available or /proc
        $pid = (int)$info['pid'];
        if ($pid > 0 && !$this->isProcessAlive($pid)) {
            // Stale lock from crashed/killed process
            @unlink($file);
            return false;
        }

        return true;
    }

    public function acquire(string $siteId, string $deploymentId, string $user, int $pid): bool
    {
        if ($this->isLocked($siteId)) {
            return false;
        }

        $file = $this->getLockFilePath($siteId);
        $lockData = [
            'site_id' => $siteId,
            'deployment_id' => $deploymentId,
            'user' => $user,
            'pid' => $pid,
            'locked_at' => time(),
            'locked_at_iso' => date('Y-m-d H:i:s')
        ];

        return safeWriteJson($file, $lockData);
    }

    public function release(string $siteId): bool
    {
        $file = $this->getLockFilePath($siteId);
        if (file_exists($file)) {
            return @unlink($file);
        }
        return true;
    }

    public function getLockInfo(string $siteId): ?array
    {
        if (!$this->isLocked($siteId)) {
            return null;
        }
        $data = safeReadJson($this->getLockFilePath($siteId), []);
        return !empty($data) ? $data : null;
    }

    private function isProcessAlive(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        $procStatFile = "/proc/$pid/status";
        if (file_exists($procStatFile)) {
            $content = @file_get_contents($procStatFile);
            if ($content !== false && preg_match('/State:\s+([Zz])/', $content)) {
                return false; // Zombie process = dead/completed!
            }
            return true;
        }

        if (function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return false;
    }
}
