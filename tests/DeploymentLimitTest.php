<?php
declare(strict_types=1);

use LightDeploy\Deployment\DailyDeploymentLimit;

function runDeploymentLimitTests(): void
{
    echo "[TEST SUITE] Running Daily Deployment Limit Tests...\n";

    $testDir = sys_get_temp_dir() . '/lightdeploy-limit-' . bin2hex(random_bytes(6));
    $limit = new DailyDeploymentLimit(
        $testDir . '/system_settings.json',
        $testDir . '/runtime/daily_counter.json',
        'Asia/Colombo'
    );
    $dayOne = new DateTimeImmutable('2026-04-20 23:59:00', new DateTimeZone('Asia/Colombo'));
    $dayTwo = new DateTimeImmutable('2026-04-21 00:00:00', new DateTimeZone('Asia/Colombo'));

    try {
        $limit->saveSettings([
            'enabled' => true,
            'daily_limit' => 2,
            'behavior' => 'block',
            'authorized_users' => [],
        ]);

        $first = $limit->reserve('deployer', false, $dayOne);
        $second = $limit->reserve('deployer', false, $dayOne);
        $blocked = $limit->reserve('deployer', false, $dayOne);
        TestRunner::assert($first['allowed'] && $second['allowed'], 'Daily limit permits deployments up to the configured cap');
        TestRunner::assert(!$blocked['allowed'] && $blocked['status']['count'] === 2, 'Block mode rejects deployments at the daily cap');

        $nextDay = $limit->reserve('deployer', false, $dayTwo);
        TestRunner::assert($nextDay['allowed'] && $nextDay['status']['count'] === 1, 'Daily deployment counter resets at midnight in configured timezone');

        $limit->saveSettings([
            'enabled' => true,
            'daily_limit' => 1,
            'behavior' => 'warn',
            'authorized_users' => ['deployer'],
        ]);
        $limit->reserve('deployer', false, $dayOne);
        $confirmation = $limit->reserve('deployer', false, $dayOne);
        $unauthorized = $limit->reserve('viewer', true, $dayOne);
        $authorized = $limit->reserve('deployer', true, $dayOne);
        TestRunner::assert(
            !$confirmation['allowed'] && !empty($confirmation['confirmation_required']),
            'Warning mode asks pre-authorized users to confirm before continuing'
        );
        TestRunner::assert(!$unauthorized['allowed'], 'Warning mode does not allow unlisted users to override the limit');
        TestRunner::assert($authorized['allowed'] && $authorized['status']['count'] === 2, 'Pre-authorized user can continue after explicit confirmation');

        $limit->release($dayOne);
        TestRunner::assert($limit->getStatus($dayOne)['count'] === 1, 'Failed deployment reservation can be released without losing other counts');
    } finally {
        $counterPath = $testDir . '/runtime/daily_counter.json';
        foreach ([$testDir . '/system_settings.json', $counterPath] as $filePath) {
            if (is_file($filePath)) {
                unlink($filePath);
            }
        }
        foreach ([$testDir . '/runtime', $testDir] as $directory) {
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
