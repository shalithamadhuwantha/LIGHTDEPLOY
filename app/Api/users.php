<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Users Management
 * GET /api/users.php     - List users
 * POST /api/users.php    - Add or Update user
 * DELETE /api/users.php  - Delete user
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

// Require user_mgmt permission
$authService->requirePermission('user_mgmt');

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $users = $authService->getUsersList();
    jsonSuccess([
        'users' => $users,
        'count' => count($users)
    ]);
}

if ($method === 'POST') {
    if (!Csrf::validateHeaderOrPost()) {
        jsonError('CSRF_ERROR', 'Invalid or missing CSRF token', 403);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $username = trim((string)($input['username'] ?? ''));
    $name = trim((string)($input['name'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $role = trim((string)($input['role'] ?? 'viewer'));
    $allowedFunctions = $input['allowed_functions'] ?? [];
    $allowedSystems = $input['allowed_systems'] ?? ['*'];

    if (empty($username)) {
        jsonError('INVALID_INPUT', 'Username is required.', 400);
    }

    if (!preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
        jsonError('INVALID_INPUT', 'Username must be 3-32 alphanumeric characters (dashes and underscores allowed).', 400);
    }

    if (!is_array($allowedFunctions)) {
        $allowedFunctions = ['sites'];
    }

    if (!is_array($allowedSystems)) {
        $allowedSystems = ['*'];
    }

    // If new user, password is required
    $existingUsers = $authService->getUsersList();
    $isNew = true;
    foreach ($existingUsers as $u) {
        if (hash_equals(strtolower($u['username']), strtolower($username))) {
            $isNew = false;
            break;
        }
    }

    if ($isNew && empty($password)) {
        jsonError('INVALID_INPUT', 'Password is required when creating a new user account.', 400);
    }

    $saved = $authService->saveUser($username, [
        'name' => $name ?: $username,
        'password' => $password,
        'role' => $role,
        'allowed_functions' => $allowedFunctions,
        'allowed_systems' => $allowedSystems,
    ]);

    if ($saved) {
        $securityLogger->log('USER_SAVED', ['target_user' => $username, 'role' => $role]);
        jsonSuccess(['message' => "User '{$username}' saved successfully."]);
    } else {
        jsonError('SERVER_ERROR', 'Failed to save user account.', 500);
    }
}

if ($method === 'DELETE') {
    if (!Csrf::validateHeaderOrPost()) {
        jsonError('CSRF_ERROR', 'Invalid or missing CSRF token', 403);
    }

    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true) ?: $_POST;

    $username = trim((string)($input['username'] ?? ($_GET['username'] ?? '')));

    if (empty($username)) {
        jsonError('INVALID_INPUT', 'Username is required.', 400);
    }

    $deleted = $authService->deleteUser($username);

    if ($deleted) {
        $securityLogger->log('USER_DELETED', ['target_user' => $username]);
        jsonSuccess(['message' => "User '{$username}' deleted successfully."]);
    } else {
        jsonError('FORBIDDEN', "Cannot delete user '{$username}' (protected admin account or current active session).", 403);
    }
}

jsonError('METHOD_NOT_ALLOWED', 'Only GET, POST, and DELETE methods are allowed.', 405);
