<?php
declare(strict_types=1);

namespace LightDeploy\Security;

class SecurityLogger
{
    private string $logDir;

    public function __construct(string $logDir)
    {
        $this->logDir = $logDir;
        ensureDirExists($this->logDir);
    }

    /**
     * Logs a security audit event safely.
     */
    public function log(string $event, array $context = [], ?string $username = null): void
    {
        $file = $this->logDir . '/audit.log';

        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $user = $username ?? ($_SESSION['username'] ?? 'ANONYMOUS');
        $role = $_SESSION['role'] ?? 'NONE';
        $timestamp = date('Y-m-d H:i:s');

        // Redact any sensitive parameters if passed accidentally
        $context = $this->redactSensitive($context);
        $contextStr = !empty($context) ? ' ' . json_encode($context, JSON_UNESCAPED_SLASHES) : '';

        $logLine = sprintf("[%s] [EVENT:%s] [USER:%s] [ROLE:%s] [IP:%s]%s%s",
            $timestamp,
            strtoupper($event),
            $user,
            $role,
            $ip,
            $contextStr,
            PHP_EOL
        );

        @file_put_contents($file, $logLine, FILE_APPEND | LOCK_EX);
    }

    private function redactSensitive(array $context): array
    {
        $sensitiveKeys = ['password', 'pass', 'token', 'csrf_token', 'secret', 'key', 'authorization'];
        foreach ($context as $key => $value) {
            if (in_array(strtolower((string)$key), $sensitiveKeys, true)) {
                $context[$key] = '***REDACTED***';
            } elseif (is_array($value)) {
                $context[$key] = $this->redactSensitive($value);
            }
        }
        return $context;
    }
}
