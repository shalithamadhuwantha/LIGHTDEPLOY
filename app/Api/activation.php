<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\ActivationService;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');
$authService->requireAuth();
$activationService = new ActivationService($config['config_dir']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    jsonSuccess($activationService->getLicenseStatus());
}

if ($method !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only GET and POST requests are permitted.', 405);
}

if (!Csrf::validateHeaderOrPost()) {
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF security token.', 403);
}

$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$action = (string)($input['action'] ?? 'activate');

if ($action === 'remove') {
    $user = $authService->requirePermission('license_mgmt');
    $password = (string)($input['password'] ?? '');
    if ($password === '' || $authService->isManagedNodeRequest() || $authService->authenticate($user['username'], $password) === null) {
        jsonError('PASSWORD_VERIFICATION_FAILED', 'Your profile password is incorrect.', 403);
    }

    try {
        $activationService->removeLicense();
    } catch (RuntimeException $error) {
        jsonError('LICENSE_REMOVAL_FAILED', $error->getMessage(), 500);
    }
    jsonSuccess(['message' => 'License removed.']);
}

if ($action !== 'activate') {
    jsonError('INVALID_ACTION', 'Unsupported license action.', 400);
}

try {
    if (!$activationService->activate((string)($input['activation_key'] ?? ''))) {
        jsonError('INVALID_ACTIVATION_KEY', 'The license is invalid, expired, or assigned to a different server IP.', 403);
    }
} catch (RuntimeException $error) {
    jsonError('LICENSE_STORAGE_FAILED', $error->getMessage(), 500);
}

jsonSuccess(array_merge($activationService->getLicenseStatus(), [
    'message' => 'License activated successfully.'
]));