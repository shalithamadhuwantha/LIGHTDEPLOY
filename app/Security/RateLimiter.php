<?php
declare(strict_types=1);

namespace LightDeploy\Security;

/**
 * LIGHTDEPLOY Rate Limiter & Brute-Force Protection
 */
class RateLimiter
{
    private string $storageDir;

    public function __construct(string $storageDir)
    {
        $this->storageDir = rtrim($storageDir, '/');
        if (!is_dir($this->storageDir)) {
            @mkdir($this->storageDir, 0750, true);
        }
    }

    /**
     * Get client IP address
     */
    public function getClientIp(): string
    {
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        if (str_contains($ip, ',')) {
            $ip = trim(explode(',', $ip)[0]);
        }
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
    }

    /**
     * Check if key/IP is allowed (opposite of isRateLimited)
     */
    public function isAllowed(string $key, int $maxAttempts = 5, int $decaySeconds = 300): bool
    {
        return !$this->isRateLimited($key, $maxAttempts, $decaySeconds);
    }

    /**
     * Check if key/IP is currently rate-limited
     */
    public function isRateLimited(string $key, int $maxAttempts = 5, int $decaySeconds = 300): bool
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return false;
        }

        $data = safeReadJson($file, ['attempts' => 0, 'first_attempt' => time()]);
        
        // Decay expired attempts
        if (time() - ($data['first_attempt'] ?? 0) > $decaySeconds) {
            @unlink($file);
            return false;
        }

        return ($data['attempts'] ?? 0) >= $maxAttempts;
    }

    /**
     * Record an attempt for a key/IP
     */
    public function hit(string $key, int $decaySeconds = 300): int
    {
        $file = $this->getFilePath($key);
        $data = safeReadJson($file, ['attempts' => 0, 'first_attempt' => time()]);

        if (time() - ($data['first_attempt'] ?? 0) > $decaySeconds) {
            $data = ['attempts' => 1, 'first_attempt' => time()];
        } else {
            $data['attempts'] = ($data['attempts'] ?? 0) + 1;
        }

        safeWriteJson($file, $data);
        return $data['attempts'];
    }

    /**
     * Reset attempt counter on success
     */
    public function clear(string $key): void
    {
        $file = $this->getFilePath($key);
        if (file_exists($file)) {
            @unlink($file);
        }
    }

    /**
     * Get remaining lockout seconds
     */
    public function getLockoutSeconds(string $key, int $decaySeconds = 300): int
    {
        $file = $this->getFilePath($key);
        if (!file_exists($file)) {
            return 0;
        }

        $data = safeReadJson($file, ['first_attempt' => time()]);
        $elapsed = time() - ($data['first_attempt'] ?? time());
        return max(0, $decaySeconds - $elapsed);
    }

    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->storageDir . '/ratelimit_' . $hash . '.json';
    }
}
