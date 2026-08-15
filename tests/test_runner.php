<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Automated Test Suite Runner
 * Run via: php tests/test_runner.php
 */

define('LIGHTDEPLOY_TESTING', true);

// Direct requirement of bootstrap
$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

// Prepare test sandbox environment paths
$testDir = __DIR__;
$testRuntime = $config['root_dir'] . '/runtime';
$testLogs = $config['root_dir'] . '/logs';
$testScripts = $config['root_dir'] . '/scripts';

class TestRunner
{
    private static int $passed = 0;
    private static int $failed = 0;
    private static array $failures = [];

    public static function assert(bool $condition, string $description): void
    {
        if ($condition) {
            self::$passed++;
            echo "  \033[32m✓ PASS:\033[0m {$description}\n";
        } else {
            self::$failed++;
            self::$failures[] = $description;
            echo "  \033[31m✗ FAIL:\033[0m {$description}\n";
        }
    }

    public static function printSummary(): void
    {
        echo "\n==================================================\n";
        echo "LIGHTDEPLOY TEST SUITE SUMMARY\n";
        echo "==================================================\n";
        echo "  Passed: \033[32m" . self::$passed . "\033[0m\n";
        echo "  Failed: \033[31m" . self::$failed . "\033[0m\n";

        if (self::$failed > 0) {
            echo "\nFailures Summary:\n";
            foreach (self::$failures as $fail) {
                echo "  - {$fail}\n";
            }
            exit(1);
        } else {
            echo "\n\033[32mALL SECURITY & DEPLOYMENT TESTS PASSED SUCCESSFULLY!\033[0m\n";
            exit(0);
        }
    }
}

echo "Starting LightDeploy Automated Test Suite...\n\n";

// Require test modules
require_once __DIR__ . '/AuthTest.php';
require_once __DIR__ . '/SecurityTest.php';
require_once __DIR__ . '/SiteValidationTest.php';
require_once __DIR__ . '/CommandInjectionTest.php';
require_once __DIR__ . '/DeploymentTest.php';

// Execute tests
runAuthTests($config);
runSecurityTests($config);
runSiteValidationTests($config);
runCommandInjectionTests($config);
runDeploymentTests($config);

TestRunner::printSummary();
