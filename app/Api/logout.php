<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Logout
 * POST /api/logout
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

if (!Csrf::validateHeaderOrPost()) {
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF token.', 403);
}

$authService->logout();
jsonSuccess(['message' => 'Logged out successfully.']);
