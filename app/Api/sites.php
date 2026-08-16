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
session_write_close();

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

    $pm2Ecosystem = $siteMeta['pm2_ecosystem'] ?? '';
    if (empty($pm2Ecosystem)) {
        $ecosystemFile = $config['config_dir'] . "/ecosystem.{$siteId}.config.js";
        if (file_exists($ecosystemFile)) {
            $pm2Ecosystem = (string)@file_get_contents($ecosystemFile);
        }
    }

    $sanitizedSites[$siteId] = [
        'id' => $siteId,
        'name' => $siteMeta['name'] ?? $siteId,
        'domain' => $siteMeta['domain'] ?? '',
        'script' => $siteMeta['script'] ?? '',
        'rollback_script' => $siteMeta['rollback_script'] ?? '',
        'health_check' => $siteMeta['health_check'] ?? '',
        'has_rollback' => !empty($siteMeta['rollback_script']),
        'health_check_enabled' => !empty($siteMeta['health_check_enabled']),
        'pm2_enabled' => !empty($siteMeta['pm2_enabled']),
        'pm2_script' => $siteMeta['pm2_script'] ?? '',
        'pm2_name' => $siteMeta['pm2_name'] ?? $siteId,
        'pm2_ecosystem' => $pm2Ecosystem,
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
