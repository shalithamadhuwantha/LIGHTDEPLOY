<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Cancel Deployment
 * POST /api/cancel
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\InputValidator;
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

$user = $authService->requireRole(['admin', 'deployer']);

if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'cancel'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF token.', 403);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$deploymentId = trim((string)($input['deployment_id'] ?? ''));

$validator = new InputValidator($config['scripts_dir']);
$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$runner = new DeploymentRunner($config['runtime_dir']);
$logger = new DeploymentLog($config['logs_dir']);
$healthChecker = new HealthChecker();

$deploymentService = new DeploymentService($validator, $lockManager, $runner, $logger, $healthChecker, $securityLogger, $config);

$result = $deploymentService->cancelDeployment($deploymentId, $user['username']);

if (!$result['success']) {
    jsonError(
        $result['error_code'] ?? 'CANCEL_FAILED',
        $result['message'] ?? 'Failed to cancel deployment.',
        $result['status_code'] ?? 400
    );
}

jsonSuccess($result);
