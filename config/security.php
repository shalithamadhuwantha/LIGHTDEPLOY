<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Security Configuration
 */

return [
    // Session Security
    'session_lifetime' => 86400, // 24 hours
    'session_name' => 'LIGHTDEPLOY_SESS',

    // Concurrency Controls
    'max_concurrent_deployments' => 1,

    // Execution Timeouts
    'default_deployment_timeout' => 1800, // 30 minutes in seconds

    // Post-Deployment Health Check Settings
    'health_check_timeout' => 10,  // seconds
    'health_check_retries' => 3,   // attempts
    'health_check_delay' => 2,     // delay between attempts in seconds

    // Rate Limiting
    'login_rate_limit_attempts' => 5,
    'login_rate_limit_window' => 900,  // 15 minutes
    'deploy_rate_limit_attempts' => 10,
    'deploy_rate_limit_window' => 60,  // 1 minute

    // Security Headers
    'enable_hsts' => true,
    'csp_policy' => "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';"
];
