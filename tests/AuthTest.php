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
}
