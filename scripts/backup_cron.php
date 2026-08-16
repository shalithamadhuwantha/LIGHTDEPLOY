<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY - CLI MySQL Automated Backup Scheduler & 7-Day Retention Cron Runner
 * Usage: php scripts/backup_cron.php [--force]
 */

if (php_sapi_name() !== 'cli') {
    echo "This script can only be executed via CLI.\n";
    exit(1);
}

$projectDir = dirname(__DIR__);
$config = require_once $projectDir . '/app/bootstrap.php';

use LightDeploy\Backup\BackupService;

$isForce = in_array('--force', $argv, true);
$backupService = new BackupService(
    $config['config_dir'] . '/databases.json',
    $config['runtime_dir']
);

$databases = $backupService->getDatabases();
echo sprintf("[%s] Starting LightDeploy Database Backup Scheduler (%d configured databases)...\n", date('Y-m-d H:i:s'), count($databases));

foreach ($databases as $dbId => $dbConfig) {
    $label = $dbConfig['label'] ?? $dbConfig['db_name'];
    $schedule = $dbConfig['schedule'] ?? 'daily';
    $lastBackup = $dbConfig['last_backup_at'] ? strtotime($dbConfig['last_backup_at']) : 0;
    $now = time();

    if ($schedule === 'disabled' && !$isForce) {
        echo sprintf(" - [%s] Schedule disabled. Skipping.\n", $label);
        continue;
    }

    $isDue = false;
    $elapsedHours = ($now - $lastBackup) / 3600;

    switch ($schedule) {
        case '6h':
            $isDue = ($elapsedHours >= 6);
            break;
        case '12h':
            $isDue = ($elapsedHours >= 12);
            break;
        case 'weekly':
            $isDue = ($elapsedHours >= 168);
            break;
        case 'daily':
        default:
            $isDue = ($elapsedHours >= 23.5);
            break;
    }

    if ($isDue || $isForce) {
        echo sprintf(" - [%s] Executing MySQL backup (Schedule: %s, Elapsed: %.1f hrs)...\n", $label, $schedule, $elapsedHours);
        try {
            $res = $backupService->runBackup($dbId, 'cron_scheduler');
            echo sprintf("   ✅ Backup successful: %s (%s). Pruned %d old backups (>7 days).\n",
                $res['filename'],
                $res['filesize_formatted'],
                $res['pruned_count']
            );
        } catch (\Throwable $e) {
            echo sprintf("   ❌ Backup failed: %s\n", $e->getMessage());
        }
    } else {
        echo sprintf(" - [%s] Not due yet (Schedule: %s, Last backup: %s). Skipping.\n",
            $label,
            $schedule,
            $dbConfig['last_backup_at'] ?: 'Never'
        );
        // Ensure 7-day retention cleanup is still evaluated
        $retentionDays = (int)($dbConfig['retention_days'] ?? 7);
        $pruned = $backupService->pruneOldBackups($dbId, $retentionDays);
        if (!empty($pruned)) {
            echo sprintf("   🧹 Auto-pruned %d expired backups older than %d days.\n", count($pruned), $retentionDays);
        }
    }
}

echo sprintf("[%s] LightDeploy Database Backup Scheduler completed.\n", date('Y-m-d H:i:s'));
