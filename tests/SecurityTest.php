<?php
declare(strict_types=1);

use LightDeploy\Security\RateLimiter;
use LightDeploy\Security\SecurityLogger;

function runSecurityTests(array $config): void
{
    echo "[TEST SUITE] Running Security & Rate Limiting Tests...\n";

    $locksDir = $config['runtime_dir'] . '/locks';
    $rateLimiter = new RateLimiter($locksDir);
    $testKey = 'test_rate_' . uniqid();

    // Test 1: Rate limiter allow under limit
    TestRunner::assert($rateLimiter->isAllowed($testKey, 3, 60) === true, "Rate limiter allows first request");
    
    $rateLimiter->hit($testKey, 60);
    $rateLimiter->hit($testKey, 60);
    $rateLimiter->hit($testKey, 60);

    // Test 2: Rate limiter block over limit
    TestRunner::assert($rateLimiter->isAllowed($testKey, 3, 60) === false, "Rate limiter blocks 4th request when max is 3");

    $rateLimiter->clear($testKey);
    TestRunner::assert($rateLimiter->isAllowed($testKey, 3, 60) === true, "Rate limiter clears key successfully");

    // Test 3: Audit logger redacts sensitive parameters
    $logDir = $config['logs_dir'] . '/security';
    $securityLogger = new SecurityLogger($logDir);

    $securityLogger->log('LOGIN_ATTEMPT', [
        'username' => 'testuser',
        'password' => 'secret123',
        'token' => 'abc123token'
    ]);

    $auditLogContent = file_get_contents($logDir . '/audit.log');
    TestRunner::assert(strpos($auditLogContent, '***REDACTED***') !== false, "Audit logger redacts sensitive password and token fields");
    TestRunner::assert(strpos($auditLogContent, 'secret123') === false, "Audit log never stores plain password");
}
