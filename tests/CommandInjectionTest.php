<?php
declare(strict_types=1);

use LightDeploy\Security\InputValidator;
use LightDeploy\Deployment\DeploymentService;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\HealthChecker;
use LightDeploy\Security\SecurityLogger;

function runCommandInjectionTests(array $config): void
{
    echo "[TEST SUITE] Running Command Injection Vulnerability Vector Attack Suite...\n";

    $validator = new InputValidator($config['scripts_dir']);
    $lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
    $runner = new DeploymentRunner($config['runtime_dir']);
    $logger = new DeploymentLog($config['logs_dir']);
    $healthChecker = new HealthChecker();
    $securityLogger = new SecurityLogger($config['logs_dir'] . '/security');

    $service = new DeploymentService(
        $validator,
        $lockManager,
        $runner,
        $logger,
        $healthChecker,
        $securityLogger,
        $config
    );

    $maliciousInputs = [
        'site-a; rm -rf /',
        'site-a && whoami',
        'site-a | id',
        '$(whoami)',
        '`whoami`',
        '../../etc/passwd',
        '/tmp/evil.sh',
        '/etc/shadow',
        "site-a\ncat /etc/passwd",
        "site-a; echo 'pwned' > /tmp/pwned.txt",
        "site-a' OR '1'='1",
        "../../../../../../bin/bash"
    ];

    foreach ($maliciousInputs as $attackPayload) {
        // Assertion 1: Validator rejects payload
        $isValid = $validator->validateSiteId($attackPayload);
        TestRunner::assert($isValid === false, "InputValidator strictly rejected attack payload: " . json_encode($attackPayload));

        // Assertion 2: DeploymentService rejects deployment trigger and returns 400
        $result = $service->startDeployment($attackPayload, [
            'name' => 'Evil Site',
            'script' => $attackPayload
        ], 'attacker', false);

        TestRunner::assert($result['success'] === false, "DeploymentService blocked attack payload execution: " . json_encode($attackPayload));
        TestRunner::assert(in_array($result['error_code'], ['INVALID_SITE_ID', 'UNAUTHORIZED_SCRIPT', 'SCRIPT_NOT_CONFIGURED'], true), "Correct security error code returned for: " . json_encode($attackPayload));
    }

    // Verify /tmp/pwned.txt was NEVER created by any shell execution
    TestRunner::assert(!file_exists('/tmp/pwned.txt'), "Verified NO arbitrary shell commands were executed by attack payloads");
}
