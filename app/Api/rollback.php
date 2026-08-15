<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Trigger Rollback
 * POST /api/rollback
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

// Rollback requires admin privilege
$user = $authService->requireRole('admin');

if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'rollback'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF token.', 403);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$siteId = trim((string)($input['site_id'] ?? ''));

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

if (!isset($configuredSites[$siteId])) {
    jsonError('INVALID_SITE_ID', 'The specified site identifier is not configured.', 400);
}

$siteConfig = $configuredSites[$siteId];
if (empty($siteConfig['rollback_script'])) {
    jsonError('NO_ROLLBACK_SCRIPT', 'No rollback script is configured for this site.', 400);
}

$validator = new InputValidator($config['scripts_dir']);
$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$runner = new DeploymentRunner($config['runtime_dir']);
$logger = new DeploymentLog($config['logs_dir']);
$healthChecker = new HealthChecker();

$deploymentService = new DeploymentService(
    $validator,
    $lockManager,
    $runner,
    $logger,
    $healthChecker,
    $securityLogger,
    $config
);

$result = $deploymentService->startDeployment($siteId, $siteConfig, $user['username'], true);

if (!$result['success']) {
    jsonError(
        $result['error_code'] ?? 'ROLLBACK_FAILED',
        $result['message'] ?? 'Failed to trigger rollback execution.',
        $result['status_code'] ?? 400,
        $result['details'] ?? null
    );
}

jsonSuccess([
    'deployment_id' => $result['deployment_id'],
    'site_id' => $result['site_id'],
    'is_rollback' => true,
    'status' => $result['status'],
    'stream_url' => '/api/stream.php?deployment_id=' . urlencode($result['deployment_id'])
], 201);
