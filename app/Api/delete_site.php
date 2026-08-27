<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Delete Configured Site
 * POST /api/delete_site
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

$authService->requireSystemAccess($siteId);

$validator = new InputValidator($config['scripts_dir']);
if (!$validator->validateSiteId($siteId)) {
    jsonError('INVALID_SITE_ID', 'Invalid site identifier format.', 400);
}

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);

if (!isset($sitesData['sites'][$siteId])) {
    jsonError('SITE_NOT_FOUND', "Site '{$siteId}' was not found in configuration.", 404);
}

$siteName = $sitesData['sites'][$siteId]['name'] ?? $siteId;
unset($sitesData['sites'][$siteId]);

if (!safeWriteJson($sitesFile, $sitesData)) {
    jsonError('WRITE_FAILED', 'Failed to update sites.json configuration file.', 500);
}

$securityLogger->log('SITE_DELETED', [
    'site_id' => $siteId,
    'name' => $siteName
], $user['username']);

jsonSuccess([
    'message' => "Site '{$siteName}' deleted successfully.",
    'site_id' => $siteId
]);
