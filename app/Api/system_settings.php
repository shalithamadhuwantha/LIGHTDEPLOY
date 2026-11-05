<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: System deployment-limit settings
 * GET /api/system_settings.php
 * POST /api/system_settings.php
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Deployment\DailyDeploymentLimit;
use LightDeploy\Security\SecurityLogger;

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    jsonError('METHOD_NOT_ALLOWED', 'Only GET and POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);
$user = $authService->requireRole('admin');
$limits = new DailyDeploymentLimit(
    $config['config_dir'] . '/system_settings.json',
    $config['runtime_dir'] . '/deployment_limits/daily_counter.json',
    $config['timezone']
);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $users = array_values(array_map(
            static fn(array $entry): string => (string)$entry['username'],
            $authService->getUsersList()
        ));
        if (!in_array($user['username'], $users, true)) {
            $users[] = $user['username'];
        }
        jsonSuccess([
            'settings' => $limits->getSettings(),
            'status' => $limits->getStatus(),
            'users' => $users,
        ]);
    } catch (\RuntimeException $exception) {
        $securityLogger->log('SYSTEM_SETTINGS_READ_FAILED', ['reason' => $exception->getMessage()], $user['username']);
        jsonError('SYSTEM_SETTINGS_ERROR', 'Failed to read deployment-limit settings or counter.', 500);
    }
}

if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'system_settings'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF security token.', 403);
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    jsonError('INVALID_INPUT', 'A valid JSON settings object is required.', 400);
}

$authorizedUsers = $input['authorized_users'] ?? [];
if (!is_array($authorizedUsers)) {
    jsonError('INVALID_INPUT', 'Authorized override users must be a list.', 400);
}

$existingUsers = [];
foreach ($authService->getUsersList() as $existingUser) {
    $existingUsers[strtolower((string)$existingUser['username'])] = (string)$existingUser['username'];
}
$existingUsers[strtolower($user['username'])] = $user['username'];

$normalizedUsers = [];
foreach ($authorizedUsers as $authorizedUser) {
    if (!is_string($authorizedUser) || !isset($existingUsers[strtolower($authorizedUser)])) {
        jsonError('INVALID_AUTHORIZED_USER', 'Every override username must match an existing user account.', 400);
    }
    $normalizedUsers[] = $existingUsers[strtolower($authorizedUser)];
}

$settings = [
    'enabled' => $input['enabled'] ?? null,
    'daily_limit' => $input['daily_limit'] ?? null,
    'behavior' => $input['behavior'] ?? null,
    'authorized_users' => $normalizedUsers,
];

try {
    $savedSettings = $limits->saveSettings($settings);
    $securityLogger->log('DEPLOYMENT_LIMIT_SETTINGS_UPDATED', [
        'enabled' => $savedSettings['enabled'],
        'daily_limit' => $savedSettings['daily_limit'],
        'behavior' => $savedSettings['behavior'],
        'authorized_users' => $savedSettings['authorized_users'],
    ], $user['username']);
    jsonSuccess([
        'message' => 'Deployment-limit settings saved successfully.',
        'settings' => $savedSettings,
        'status' => $limits->getStatus(),
    ]);
} catch (\InvalidArgumentException $exception) {
    jsonError('INVALID_SETTINGS', $exception->getMessage(), 400);
} catch (\RuntimeException $exception) {
    $securityLogger->log('SYSTEM_SETTINGS_SAVE_FAILED', ['reason' => $exception->getMessage()], $user['username']);
    jsonError('SYSTEM_SETTINGS_ERROR', 'Failed to save deployment-limit settings.', 500);
}
