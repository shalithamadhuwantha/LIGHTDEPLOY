<?php
declare(strict_types=1);

use LightDeploy\Security\InputValidator;

function runSiteValidationTests(array $config): void
{
    echo "[TEST SUITE] Running Site Allowlist & Path Security Tests...\n";

    $validator = new InputValidator($config['scripts_dir']);

    // Test 1: Valid site IDs
    TestRunner::assert($validator->validateSiteId('site-a') === true, "Valid site ID 'site-a' accepted");
    TestRunner::assert($validator->validateSiteId('site_b_123') === true, "Valid site ID 'site_b_123' accepted");

    // Test 2: Invalid site IDs
    TestRunner::assert($validator->validateSiteId('../site-a') === false, "Path traversal site ID '../site-a' rejected");
    TestRunner::assert($validator->validateSiteId('site-a; rm -rf /') === false, "Command injection site ID rejected");
    TestRunner::assert($validator->validateSiteId('site-a\0.sh') === false, "Null byte site ID rejected");

    // Test 3: Script path validation
    $validScript = $config['scripts_dir'] . '/site-a.sh';
    TestRunner::assert($validator->validateScriptPath($validScript) === true, "Valid deployment script 'site-a.sh' accepted");

    // Test 4: Path traversal outside scripts directory
    $invalidScript = $config['scripts_dir'] . '/../config/sites.json';
    TestRunner::assert($validator->validateScriptPath($invalidScript) === false, "Path traversal escaping scripts directory rejected");

    // Test 5: Non-executable or non-sh file
    $nonShScript = $config['scripts_dir'] . '/test.txt';
    @file_put_contents($nonShScript, 'test');
    TestRunner::assert($validator->validateScriptPath($nonShScript) === false, "Non-.sh extension file rejected");
    @unlink($nonShScript);
}
