<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Configuration Loader
 */

$rootDir = dirname(__DIR__);

$securityConfig = file_exists($rootDir . '/config/security.php')
    ? require $rootDir . '/config/security.php'
    : [];

$defaultSecurity = [
    'session_lifetime' => 86400, // 24 hours
    'max_concurrent_deployments' => 1,
    'default_deployment_timeout' => 1800, // 30 minutes
    'health_check_timeout' => 10, // 10 seconds
    'health_check_retries' => 3,
    'health_check_delay' => 2, // 2 seconds between retries
    'login_rate_limit_attempts' => 5,
    'login_rate_limit_window' => 900, // 15 minutes
    'deploy_rate_limit_attempts' => 10,
    'deploy_rate_limit_window' => 60, // 1 minute
];

return [
    'timezone' => 'Asia/Colombo',
    'root_dir' => $rootDir,
    'app_dir' => $rootDir . '/app',
    'public_dir' => $rootDir . '/public',
    'config_dir' => $rootDir . '/config',
    'logs_dir' => $rootDir . '/logs',
    'runtime_dir' => $rootDir . '/runtime',
    'scripts_dir' => $rootDir . '/scripts',
    'security' => array_merge($defaultSecurity, $securityConfig),
];
