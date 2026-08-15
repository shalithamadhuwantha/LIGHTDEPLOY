<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Deploy Site
 * POST /api/deploy
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\InputValidator;
use LightDeploy\Security\RateLimiter;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\HealthChecker;
use LightDeploy\Deployment\DeploymentService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Require admin or deployer role
$user = $authService->requireRole(['admin', 'deployer']);

// Validate CSRF token
if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'deploy'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF security token.', 403);
}

// Rate Limiting
$rateLimiter = new RateLimiter($config['runtime_dir'] . '/locks');
$rateKey = 'deploy_user_' . $user['username'];
$maxAttempts = (int)($config['security']['deploy_rate_limit_attempts'] ?? 10);
$window = (int)($config['security']['deploy_rate_limit_window'] ?? 60);

if (!$rateLimiter->isAllowed($rateKey, $maxAttempts, $window)) {
    $securityLogger->log('RATE_LIMIT', ['endpoint' => 'deploy'], $user['username']);
    jsonError('TOO_MANY_REQUESTS', 'Deployment rate limit exceeded. Please wait before triggering another deployment.', 429);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$siteId = trim((string)($input['site_id'] ?? ''));

// Validate site exists in server-side configuration allowlist
$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

if (!isset($configuredSites[$siteId])) {
    $securityLogger->log('MALICIOUS_INPUT_ATTEMPT', ['invalid_site_id' => $siteId], $user['username']);
    jsonError('INVALID_SITE_ID', 'The specified site identifier is not configured in the server allowlist.', 400);
}

$siteConfig = $configuredSites[$siteId];
if (empty($siteConfig['enabled'])) {
    jsonError('SITE_DISABLED', 'Deployments for this site are currently disabled.', 400);
}

// Initialize Deployment Engine
$validator = new InputValidator($config['scripts_dir']);
$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$runner = new DeploymentRunner($config['runtime_dir']);
$logger = new DeploymentLog($config['logs_dir']);
$healthChecker = new HealthChecker(
    (int)($config['security']['health_check_timeout'] ?? 10),
    (int)($config['security']['health_check_retries'] ?? 3),
    (int)($config['security']['health_check_delay'] ?? 2)
);

$deploymentService = new DeploymentService(
    $validator,
    $lockManager,
    $runner,
    $logger,
    $healthChecker,
    $securityLogger,
    $config
);

$result = $deploymentService->startDeployment($siteId, $siteConfig, $user['username'], false);

if (!$result['success']) {
    jsonError(
        $result['error_code'] ?? 'DEPLOYMENT_FAILED',
        $result['message'] ?? 'Failed to trigger deployment.',
        $result['status_code'] ?? 400,
        $result['details'] ?? null
    );
}

$rateLimiter->hit($rateKey, $window);

jsonSuccess([
    'deployment_id' => $result['deployment_id'],
    'site_id' => $result['site_id'],
    'status' => $result['status'],
    'stream_url' => '/api/stream.php?deployment_id=' . urlencode($result['deployment_id'])
], 201);
