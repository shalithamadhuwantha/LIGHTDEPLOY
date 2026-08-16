<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Deployment History
 * GET /api/history
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Deployment\DeploymentLog;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$user = $authService->requireAuth();
session_write_close();

$limit = isset($_GET['limit']) ? min(100, max(1, (int)$_GET['limit'])) : 50;

$deploymentLog = new DeploymentLog($config['logs_dir']);
$history = $deploymentLog->getHistory($limit);

jsonSuccess(['history' => $history]);
