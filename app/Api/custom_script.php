<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Run a site's saved custom bash script
 * POST /api/custom_script
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentService;
use LightDeploy\Deployment\HealthChecker;
use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);
$user = $authService->requirePermission('add_edit_sites');

if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'custom_script'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF security token.', 403);
}

$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$siteId = strtolower(trim((string)($input['site_id'] ?? '')));
$authService->requireSystemAccess($siteId);

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$siteConfig = $sitesData['sites'][$siteId] ?? null;
if (!is_array($siteConfig)) {
    jsonError('INVALID_SITE_ID', 'The specified site identifier is not configured.', 400);
}
if (empty($siteConfig['enabled'])) {
    jsonError('SITE_DISABLED', 'Custom scripts cannot run for a disabled site.', 400);
}

$scriptPath = trim((string)($siteConfig['custom_script_path'] ?? ''));
$validator = new InputValidator($config['scripts_dir']);
if ($scriptPath === '' || !$validator->validateScriptPath($scriptPath)) {
    jsonError('CUSTOM_SCRIPT_NOT_CONFIGURED', 'Save a valid executable custom .sh script for this site first.', 400);
}

$service = new DeploymentService(
    $validator,
    new DeploymentLock($config['runtime_dir'] . '/locks'),
    new DeploymentRunner($config['runtime_dir']),
    new DeploymentLog($config['logs_dir']),
    new HealthChecker(
        (int)($config['security']['health_check_timeout'] ?? 10),
        (int)($config['security']['health_check_retries'] ?? 3),
        (int)($config['security']['health_check_delay'] ?? 2)
    ),
    $securityLogger,
    $config
);

$customSiteConfig = $siteConfig;
$customSiteConfig['script'] = $scriptPath;
$customSiteConfig['health_check_enabled'] = false;
$result = $service->startDeployment($siteId, $customSiteConfig, $user['username']);

if (!$result['success']) {
    jsonError($result['error_code'] ?? 'CUSTOM_SCRIPT_FAILED', $result['message'] ?? 'Failed to run custom script.', $result['status_code'] ?? 400, $result['details'] ?? null);
}

$securityLogger->log('CUSTOM_SCRIPT_STARTED', [
    'site_id' => $siteId,
    'script' => $scriptPath,
    'deployment_id' => $result['deployment_id']
], $user['username']);

jsonSuccess([
    'message' => 'Custom script started successfully.',
    'deployment_id' => $result['deployment_id'],
    'site_id' => $siteId,
    'stream_url' => '/api/stream.php?deployment_id=' . urlencode($result['deployment_id'])
], 201);
