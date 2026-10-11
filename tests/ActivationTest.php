<?php
declare(strict_types=1);

use LightDeploy\Auth\ActivationService;
use LightDeploy\Auth\AuthService;

function runActivationTests(): void
{
    echo "[TEST SUITE] Running Activation Tests...\n";

    $testConfigDir = sys_get_temp_dir() . '/lightdeploy-activation-' . bin2hex(random_bytes(6));
    mkdir($testConfigDir, 0700, true);
    $activationService = new ActivationService($testConfigDir, '127.0.0.1');
    $validKey = base64_encode('Cybernetic@2099.12.31@127.0.0.1');
    $lifetimeKey = base64_encode('Cybernetic@0000.00.00@127.0.0.1');

    TestRunner::assert(!$activationService->isActivated(), 'New installation starts unactivated');
    TestRunner::assert(!$activationService->activate('not-a-license'), 'Reject malformed Base64 license keys');
    TestRunner::assert(!$activationService->activate(base64_encode('Cybernetic@2099.12.31@127.0.0.2')), 'Reject a license for another server IP');
    TestRunner::assert(!$activationService->activate(base64_encode('Cybernetic@2000.01.01@127.0.0.1')), 'Reject expired licenses');
    TestRunner::assert(!$activationService->activate(base64_encode('Cybernetic@2026.02.30@127.0.0.1')), 'Reject invalid expiry dates');
    TestRunner::assert($activationService->activate($validKey), 'Accept a valid Base64 license for this server');
    TestRunner::assert($activationService->isActivated(), 'Persist and validate license state');

    $licenseStatus = $activationService->getLicenseStatus();
    TestRunner::assert($licenseStatus['company'] === 'Cybernetic' && $licenseStatus['valid_until'] === '2099.12.31', 'Expose company and expiry details');
    TestRunner::assert(!(new ActivationService($testConfigDir, '127.0.0.2'))->isActivated(), 'Recheck server binding after activation');
    TestRunner::assert($activationService->activate($lifetimeKey), 'Accept the all-zero lifetime expiry');
    TestRunner::assert($activationService->getLicenseStatus()['valid_until'] === 'Lifetime', 'Report lifetime license status');

    $previousSession = $_SESSION;
    $_SESSION['authenticated'] = true;
    $_SESSION['username'] = 'license-manager';
    $_SESSION['role'] = 'viewer';
    $_SESSION['allowed_functions'] = ['sites', 'license_mgmt'];
    $_SESSION['allowed_systems'] = ['*'];
    $_SESSION['last_activity'] = time();
    $_SESSION['client_ua'] = md5($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
    $authService = new AuthService($testConfigDir . '/users.json');
    TestRunner::assert($authService->hasPermission('license_mgmt'), 'Allow license removal for a selected user');

    $_SESSION['allowed_functions'] = ['*'];
    $_SESSION['role'] = 'admin';
    TestRunner::assert(!$authService->hasPermission('license_mgmt'), 'Require explicit license permission even for admins');
    $_SESSION['allowed_functions'] = ['*', 'license_mgmt'];
    TestRunner::assert($authService->hasPermission('license_mgmt'), 'Allow admins explicitly selected for license removal');
    $_SESSION = $previousSession;

    $activationService->removeLicense();
    TestRunner::assert(!$activationService->isActivated(), 'Remove the saved license and return to unactivated state');
    TestRunner::assert(!$activationService->getLicenseStatus()['has_license'], 'Clear stored license details after removal');

    rmdir($testConfigDir);
}