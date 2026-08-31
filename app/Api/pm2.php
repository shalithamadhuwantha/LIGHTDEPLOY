<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: PM2 Process Manager Endpoint
 * GET /api/pm2  - Fetch status & process list / logs
 * POST /api/pm2 - Execute restart/stop/reload/start/delete/install actions
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\PM2\PM2Manager;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Permission Required
$user = $authService->requirePermission('pm2');
session_write_close();

$pm2 = new PM2Manager();

// GET Request: List or Logs
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $action = $_GET['action'] ?? 'list';
    $target = $_GET['target'] ?? 'all';

    if ($action === 'logs') {
        $logs = $pm2->getLogs((string)$target, 150);
        jsonSuccess([
            'target' => $target,
            'logs' => $logs
        ]);
    }

    $installed = $pm2->isInstalled();
    $version = $installed ? $pm2->getVersion() : null;
    $processes = $installed ? $pm2->listProcesses() : [];

    jsonSuccess([
        'installed' => $installed,
        'version' => $version,
        'processes' => $processes,
        'count' => count($processes)
    ]);
}

// POST Request: Process Control Actions (Admin, Deployer, Developer, or pm2 permission required)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!in_array($user['role'] ?? '', ['admin', 'deployer', 'developer'], true) && !$authService->hasPermission('pm2')) {
        jsonError('FORBIDDEN', 'Insufficient permissions to perform PM2 process control actions.', 403);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $action = strtolower(trim((string)($input['action'] ?? '')));
    $target = trim((string)($input['target'] ?? 'all'));

    if ($action === 'install') {
        if ($user['role'] !== 'admin') {
            jsonError('FORBIDDEN', 'Only administrators can install system packages.', 403);
        }
        $res = $pm2->autoInstall();
        if (!$res['success']) {
            jsonError('INSTALL_FAILED', $res['error'], 500);
        }
        $securityLogger->log('PM2_INSTALLED', [], $user['username']);
        jsonSuccess(['message' => $res['message'], 'output' => $res['output']]);
    }

    if ($action === 'start_app') {
        $script = trim((string)($input['script'] ?? ''));
        $name = trim((string)($input['name'] ?? ''));
        $cwd = trim((string)($input['cwd'] ?? ''));

        if (empty($script)) {
            jsonError('INVALID_INPUT', 'Script or application file path is required.', 400);
        }

        $res = $pm2->startApp($script, $name ?: null, $cwd ?: null);
        if (!$res['success']) {
            jsonError('START_FAILED', $res['error'], 500);
        }

        $securityLogger->log('PM2_START_APP', ['script' => $script, 'name' => $name], $user['username']);
        jsonSuccess(['message' => 'Process launched with PM2!', 'cmd' => $res['cmd'] ?? '', 'output' => $res['output']]);
    }

    if ($action === 'update_config') {
        $name = trim((string)($input['name'] ?? ''));
        $script = trim((string)($input['script'] ?? ''));

        if (empty($name) || empty($script)) {
            jsonError('INVALID_INPUT', 'PM2 Process Name and Script File path are required.', 400);
        }

        $res = $pm2->updateProcessConfig($input);
        if (!$res['success']) {
            jsonError('UPDATE_FAILED', $res['error'], 500);
        }

        $securityLogger->log('PM2_CONFIG_UPDATED', ['name' => $name, 'script' => $script], $user['username']);
        jsonSuccess(['message' => "PM2 process '{$name}' ecosystem configuration updated & restarted!", 'output' => $res['output']]);
    }

    $result = $pm2->executeAction($action, $target);
    if (!$result['success']) {
        jsonError('ACTION_FAILED', $result['error'], 500);
    }

    $securityLogger->log('PM2_ACTION', ['action' => $action, 'target' => $target], $user['username']);

    jsonSuccess([
        'message' => "PM2 action '{$action}' executed on '{$target}' successfully.",
        'action' => $action,
        'target' => $target,
        'output' => $result['output']
    ]);
}

jsonError('METHOD_NOT_ALLOWED', 'Only GET and POST requests are supported.', 405);
