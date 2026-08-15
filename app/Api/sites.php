<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Configured Sites Listing
 * GET /api/sites
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentLog;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$user = $authService->requireAuth();

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$deploymentLog = new DeploymentLog($config['logs_dir']);

// Check single site query
$siteIdFilter = $_GET['id'] ?? null;

$sanitizedSites = [];
foreach ($configuredSites as $siteId => $siteMeta) {
    if ($siteIdFilter !== null && $siteIdFilter !== $siteId) {
        continue;
    }

    $isLocked = $lockManager->isLocked($siteId);
    $lockInfo = $isLocked ? $lockManager->getLockInfo($siteId) : null;

    // Get last deployment for this site from history
    $history = $deploymentLog->getHistory(50);
    $lastDeployment = null;
    foreach ($history as $h) {
        if (($h['site_id'] ?? '') === $siteId) {
            $lastDeployment = [
                'deployment_id' => $h['deployment_id'],
                'status' => $h['status'],
                'start_time' => $h['start_time'],
                'duration' => $h['duration'],
                'user' => $h['user']
            ];
            break;
        }
    }

    $sanitizedSites[$siteId] = [
        'id' => $siteId,
        'name' => $siteMeta['name'] ?? $siteId,
        'domain' => $siteMeta['domain'] ?? '',
        'has_rollback' => !empty($siteMeta['rollback_script']),
        'health_check_enabled' => !empty($siteMeta['health_check_enabled']),
        'enabled' => !empty($siteMeta['enabled']),
        'is_locked' => $isLocked,
        'active_lock' => $lockInfo,
        'last_deployment' => $lastDeployment
    ];
}

if ($siteIdFilter !== null) {
    if (isset($sanitizedSites[$siteIdFilter])) {
        jsonSuccess(['site' => $sanitizedSites[$siteIdFilter]]);
    } else {
        jsonError('SITE_NOT_FOUND', "Configured site '{$siteIdFilter}' not found.", 404);
    }
}

jsonSuccess(['sites' => $sanitizedSites]);
