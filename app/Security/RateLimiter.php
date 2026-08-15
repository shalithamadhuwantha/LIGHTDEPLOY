<?php
declare(strict_types=1);

namespace LightDeploy\Security;

class RateLimiter
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = $storageDir;
        ensureDirExists($this->storageDir);
    }

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->storageDir . '/rate_' . $hash . '.json';
    }

    /**
     * Checks if the rate limit has been exceeded.
     */
    public function isAllowed(string $key, int $maxAttempts, int $windowSeconds): bool
    {
        $filePath = $this->getFilePath($key);
        $data = safeReadJson($filePath, ['attempts' => []]);

        $now = time();
        $validAttempts = array_filter($data['attempts'] ?? [], function ($timestamp) use ($now, $windowSeconds) {
            return ($now - $timestamp) < $windowSeconds;
        });

        return count($validAttempts) < $maxAttempts;
    }

    /**
     * Records an attempt.
     */
    public function hit(string $key, int $windowSeconds): void
    {
        $filePath = $this->getFilePath($key);
        $data = safeReadJson($filePath, ['attempts' => []]);

        $now = time();
        $validAttempts = array_filter($data['attempts'] ?? [], function ($timestamp) use ($now, $windowSeconds) {
            return ($now - $timestamp) < $windowSeconds;
        });

        $validAttempts[] = $now;

        safeWriteJson($filePath, ['attempts' => array_values($validAttempts)]);
    }

    /**
     * Resets rate limit attempts for a key.
     */
    public function clear(string $key): void
    {
        $filePath = $this->getFilePath($key);
        if (file_exists($filePath)) {
            @unlink($filePath);
        }
    }
}
