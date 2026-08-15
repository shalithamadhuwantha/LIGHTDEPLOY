<?php
declare(strict_types=1);

use LightDeploy\Security\InputValidator;
use LightDeploy\Deployment\DeploymentService;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\HealthChecker;

function runDeploymentTests(array $config): void
{
    echo "[TEST SUITE] Running End-to-End Deployment Engine Tests...\n";

    $validator = new InputValidator($config['scripts_dir']);
    $lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
    $runner = new DeploymentRunner($config['runtime_dir']);
    $logger = new DeploymentLog($config['logs_dir']);
    $healthChecker = new HealthChecker(5, 1, 1);

    $service = new DeploymentService(
        $validator,
        $lockManager,
        $runner,
        $logger,
        $healthChecker,
        null,
        $config
    );

    $sitesFile = $config['config_dir'] . '/sites.json';
    $sitesData = safeReadJson($sitesFile, ['sites' => []]);
    $siteConfig = $sitesData['sites']['site-a'] ?? [];

    // Test 1: Start Deployment
    $res = $service->startDeployment('site-a', $siteConfig, 'testrunner', false);
    TestRunner::assert($res['success'] === true && !empty($res['deployment_id']), "Successfully triggered deployment for site-a");

    $depId = $res['deployment_id'];

    // Test 2: Concurrency Lock Rejection
    $dupRes = $service->startDeployment('site-a', $siteConfig, 'testrunner', false);
    TestRunner::assert($dupRes['success'] === false && $dupRes['error_code'] === 'DEPLOYMENT_ALREADY_RUNNING', "Blocked concurrent deployment for site-a (409 Conflict)");

    // Test 3: Wait for process completion and verify output stream
    $maxWait = 10;
    $done = false;
    while ($maxWait > 0) {
        $state = $service->updateDeploymentState($depId, $siteConfig);
        if (in_array($state['status'], ['success', 'failed', 'cancelled', 'timeout', 'health_check_failed'], true)) {
            $done = true;
            break;
        }
        sleep(1);
        $maxWait--;
    }

    TestRunner::assert($done === true && ($state['status'] === 'success' || $state['status'] === 'health_check_failed'), "Deployment process completed execution within 10s");

    // Test 4: Lock released after completion
    TestRunner::assert($lockManager->isLocked('site-a') === false, "Deployment lock automatically released upon completion");

    // Test 5: Permanent log archive created
    $logData = $logger->getLog($depId);
    TestRunner::assert($logData !== null && strpos($logData['output'], '[DONE]') !== false, "Deployment stdout log file successfully archived");

    // Test 6: Cancel active deployment
    $cancelRes = $service->startDeployment('site-b', $sitesData['sites']['site-b'], 'testrunner', false);
    if ($cancelRes['success']) {
        $cancelDepId = $cancelRes['deployment_id'];
        $cResult = $service->cancelDeployment($cancelDepId, 'testrunner');
        TestRunner::assert($cResult['success'] === true, "Cancel deployment terminated running process safely");
        TestRunner::assert($lockManager->isLocked('site-b') === false, "Lock released after cancellation");
    }
}
