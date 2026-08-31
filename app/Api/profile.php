<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: User Self Profile Management
 * GET /api/profile.php  - Retrieve logged-in user profile
 * POST /api/profile.php - Update logged-in user display name & password
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Require Authentication for profile management
$currentUser = $authService->requireAuth();

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    jsonSuccess([
        'user' => [
            'username' => $currentUser['username'],
            'name' => $currentUser['name'],
            'role' => $currentUser['role'],
            'allowed_functions' => $currentUser['allowed_functions'],
            'allowed_systems' => $currentUser['allowed_systems'],
        ]
    ]);
}

if ($method === 'POST') {
    if (!Csrf::validateHeaderOrPost()) {
        jsonError('CSRF_ERROR', 'Invalid or missing CSRF token.', 403);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');

    if (empty($name)) {
        jsonError('INVALID_INPUT', 'Display name cannot be empty.', 400);
    }

    if (!empty($password) && strlen($password) < 6) {
        jsonError('INVALID_INPUT', 'New password must be at least 6 characters in length.', 400);
    }

    $updated = $authService->updateProfile($currentUser['username'], $name, !empty($password) ? $password : null);

    if ($updated) {
        $securityLogger->log('PROFILE_UPDATED', [
            'username' => $currentUser['username'],
            'updated_name' => $name,
            'password_changed' => !empty($password)
        ], $currentUser['username']);

        jsonSuccess([
            'message' => 'Profile updated successfully.',
            'name' => $name
        ]);
    } else {
        jsonError('SERVER_ERROR', 'Failed to update profile settings.', 500);
    }
}

jsonError('METHOD_NOT_ALLOWED', 'Only GET and POST methods are permitted.', 405);
