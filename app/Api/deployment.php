<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Get Deployment Status
 * GET /api/deployment?id=...
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\HealthChecker;
use LightDeploy\Deployment\DeploymentService;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$user = $authService->requireAuth();
session_write_close();

$deploymentId = trim((string)($_GET['id'] ?? ''));

$validator = new InputValidator($config['scripts_dir']);
if (!$validator->validateDeploymentId($deploymentId)) {
    jsonError('INVALID_DEPLOYMENT_ID', 'Invalid deployment ID format.', 400);
}

$runner = new DeploymentRunner($config['runtime_dir']);
$logger = new DeploymentLog($config['logs_dir']);
$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$healthChecker = new HealthChecker();
$deploymentService = new DeploymentService($validator, $lockManager, $runner, $logger, $healthChecker, $securityLogger, $config);

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

$jobPath = $runner->getJobPath($deploymentId);
if (file_exists($jobPath)) {
    $meta = safeReadJson($jobPath, []);
    $siteId = $meta['site_id'] ?? '';
    $siteConfig = $configuredSites[$siteId] ?? null;

    $updatedMeta = $deploymentService->updateDeploymentState($deploymentId, $siteConfig);
    
    $streamPath = $runner->getStreamPath($deploymentId);
    $output = file_exists($streamPath) ? file_get_contents($streamPath) : '';

    jsonSuccess([
        'deployment' => $updatedMeta,
        'output' => $output
    ]);
}

// Fallback to permanent log archive if job is completed and cleaned up
$archived = $logger->getLog($deploymentId);
if ($archived) {
    jsonSuccess([
        'deployment' => $archived['meta'],
        'output' => $archived['output']
    ]);
}

jsonError('DEPLOYMENT_NOT_FOUND', "Deployment ID {$deploymentId} was not found.", 404);
