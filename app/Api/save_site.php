<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Add / Edit Configured Site
 * POST /api/save_site
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Permission Required
$user = $authService->requirePermission('add_edit_sites');
session_write_close();

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$siteId = strtolower(trim((string)($input['site_id'] ?? '')));

if (!empty($siteId)) {
    $authService->requireSystemAccess($siteId);
}
$name = trim((string)($input['name'] ?? ''));
$domain = trim((string)($input['domain'] ?? ''));
$script = trim((string)($input['script'] ?? ''));
$rollbackScript = trim((string)($input['rollback_script'] ?? ''));
$healthCheck = trim((string)($input['health_check'] ?? ''));
$healthCheckEnabled = !empty($input['health_check_enabled']);
$pm2Enabled = !empty($input['pm2_enabled']);
$pm2Script = trim((string)($input['pm2_script'] ?? ''));
$pm2Name = trim((string)($input['pm2_name'] ?? $siteId));
$pm2Ecosystem = trim((string)($input['pm2_ecosystem'] ?? ''));
$enabled = isset($input['enabled']) ? !empty($input['enabled']) : true;

// Validate site_id
$validator = new InputValidator($config['scripts_dir']);
if (!$validator->validateSiteId($siteId)) {
    jsonError('INVALID_SITE_ID', 'Site ID must be 3-32 characters long and contain only letters, numbers, hyphens, or underscores.', 400);
}

if (empty($name)) {
    jsonError('INVALID_INPUT', 'Site display name is required.', 400);
}

// Default script path if empty
if (empty($script)) {
    $script = "scripts/{$siteId}.sh";
}

// PM2 Ecosystem file saving & auto-launch if enabled
$ecosystemFilePath = null;
if ($pm2Enabled) {
    $ecosystemFileName = "ecosystem.{$siteId}.config.js";
    $ecosystemFilePath = $config['config_dir'] . '/' . $ecosystemFileName;

    if (!empty($pm2Ecosystem)) {
        @file_put_contents($ecosystemFilePath, $pm2Ecosystem, LOCK_EX);
    }

    $pm2Manager = new \LightDeploy\PM2\PM2Manager();
    if ($pm2Manager->isInstalled()) {
        if (!empty($pm2Ecosystem) && file_exists($ecosystemFilePath)) {
            $pm2Manager->startApp($ecosystemFilePath);
        } elseif (!empty($pm2Script)) {
            $pm2Manager->startApp($pm2Script, $pm2Name);
        }
    }
}

// Create script file if it does not exist
$scriptFullPath = $config['scripts_dir'] . '/' . basename($script);
if (!file_exists($scriptFullPath)) {
    $pm2Step = "";
    if ($pm2Enabled) {
        if (!empty($pm2Ecosystem) && file_exists($ecosystemFilePath)) {
            $pm2Step = "echo \"[PM2] Reloading PM2 ecosystem config {$ecosystemFileName}...\"\nif command -v pm2 >/dev/null 2>&1; then pm2 reload {$ecosystemFilePath} || pm2 start {$ecosystemFilePath}; fi\n";
        } else {
            $pm2Step = "echo \"[PM2] Reloading PM2 process {$pm2Name}...\"\nif command -v pm2 >/dev/null 2>&1; then pm2 reload {$pm2Name} || pm2 start {$pm2Script} --name {$pm2Name}; fi\n";
        }
    }

    $template = "#!/bin/bash\n" .
                "# LightDeploy Script: {$name}\n" .
                "set -e\n\n" .
                "echo \"[START] \$(date '+%H:%M:%S') Starting deployment for {$name}...\"\n" .
                "echo \"[INFO] Deployment ID: \${DEPLOYMENT_ID:-DEP-LOCAL}\"\n" .
                "echo \"[INFO] Triggered by user: \${DEPLOYED_BY:-admin}\"\n\n" .
                "sleep 1\n" .
                "echo \"[SYNC] Synchronizing code and assets...\"\n" .
                "{$pm2Step}" .
                "sleep 1\n" .
                "echo \"[DONE] \$(date '+%H:%M:%S') Deployment for {$name} completed successfully!\"\n" .
                "exit 0\n";
    @file_put_contents($scriptFullPath, $template, LOCK_EX);
    @chmod($scriptFullPath, 0755);
}

// Create rollback script if specified and missing
if (!empty($rollbackScript)) {
    $rollbackFullPath = $config['scripts_dir'] . '/' . basename($rollbackScript);
    if (!file_exists($rollbackFullPath)) {
        $rollbackTemplate = "#!/bin/bash\n" .
                            "# LightDeploy Rollback Script: {$name}\n" .
                            "set -e\n\n" .
                            "echo \"[START] \$(date '+%H:%M:%S') Reverting deployment for {$name}...\"\n" .
                            "sleep 1\n" .
                            "echo \"[DONE] Rollback for {$name} completed successfully!\"\n" .
                            "exit 0\n";
        @file_put_contents($rollbackFullPath, $rollbackTemplate, LOCK_EX);
        @chmod($rollbackFullPath, 0755);
    }
}

// Load existing sites configuration
$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);

$sitesData['sites'][$siteId] = [
    'name' => $name,
    'domain' => $domain,
    'script' => $script,
    'rollback_script' => $rollbackScript,
    'health_check' => $healthCheck,
    'health_check_enabled' => $healthCheckEnabled,
    'pm2_enabled' => $pm2Enabled,
    'pm2_script' => $pm2Script,
    'pm2_name' => $pm2Name,
    'pm2_ecosystem' => $pm2Ecosystem,
    'enabled' => $enabled
];

if (!safeWriteJson($sitesFile, $sitesData)) {
    jsonError('WRITE_FAILED', 'Failed to update sites.json configuration file.', 500);
}

$securityLogger->log('SITE_CONFIGURED', [
    'site_id' => $siteId,
    'name' => $name,
    'script' => $script
], $user['username']);

jsonSuccess([
    'message' => "Site '{$name}' configured successfully.",
    'site_id' => $siteId,
    'site' => $sitesData['sites'][$siteId]
]);
