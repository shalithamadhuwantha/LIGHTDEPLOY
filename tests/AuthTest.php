<?php
declare(strict_types=1);

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

function runAuthTests(array $config): void
{
    echo "[TEST SUITE] Running Authentication & Session Tests...\n";

    $usersFile = $config['config_dir'] . '/users.json';
    $authService = new AuthService($usersFile);

    // Test 1: Valid credential authentication
    $user = $authService->authenticate('admin', 'admin123');
    TestRunner::assert($user !== null && $user['role'] === 'admin', "Authenticate admin user with valid password");

    // Test 2: Invalid password rejection
    $invalidUser = $authService->authenticate('admin', 'wrongpass');
    TestRunner::assert($invalidUser === null, "Reject admin user with invalid password");

    // Test 3: Unknown user rejection
    $unknownUser = $authService->authenticate('nonexistent', 'password');
    TestRunner::assert($unknownUser === null, "Reject nonexistent username");

    // Test 4: CSRF token generation and validation
    $token = Csrf::getToken();
    TestRunner::assert(!empty($token) && strlen($token) === 64, "Generate 64-character hex CSRF token");
    TestRunner::assert(Csrf::validateToken($token) === true, "Validate matching CSRF token");
    TestRunner::assert(Csrf::validateToken('invalid_token') === false, "Reject invalid CSRF token");

    $managedDir = sys_get_temp_dir() . '/lightdeploy-managed-auth-' . bin2hex(random_bytes(6));
    mkdir($managedDir, 0700, true);
    $nodeToken = str_repeat('A', 48);
    safeWriteJson($managedDir . '/users.json', ['users' => []]);
    safeWriteJson($managedDir . '/node_control.json', ['token_hash' => hash('sha256', $nodeToken)]);
    $previousToken = $_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN'] ?? null;
    $previousOperator = $_SERVER['HTTP_X_LIGHTDEPLOY_OPERATOR'] ?? null;

    try {
        $_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN'] = 'Bearer ' . $nodeToken;
        $_SERVER['HTTP_X_LIGHTDEPLOY_OPERATOR'] = 'release_manager';
        $managedUser = (new AuthService($managedDir . '/users.json'))->getCurrentUser();
        TestRunner::assert(
            ($managedUser['username'] ?? '') === 'release_manager',
            'Use authenticated control-plane operator identity for managed-node deployments'
        );
    } finally {
        if ($previousToken === null) {
            unset($_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN']);
        } else {
            $_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN'] = $previousToken;
        }
        if ($previousOperator === null) {
            unset($_SERVER['HTTP_X_LIGHTDEPLOY_OPERATOR']);
        } else {
            $_SERVER['HTTP_X_LIGHTDEPLOY_OPERATOR'] = $previousOperator;
        }
        unlink($managedDir . '/users.json');
        unlink($managedDir . '/node_control.json');
        rmdir($managedDir);
    }
}
