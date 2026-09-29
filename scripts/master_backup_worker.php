<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

use LightDeploy\Backup\BackupService;

$jobId = trim((string)($argv[1] ?? ''));
$format = trim((string)($argv[2] ?? 'sql'));
if (!preg_match('/^MBK-\d{14}-[a-f0-9]{10}$/', $jobId)) {
    fwrite(STDERR, "Invalid backup job ID.\n");
    exit(2);
}

$jobsDir = $config['runtime_dir'] . '/master_backup_jobs';
$jobPath = $jobsDir . '/' . $jobId . '.json';
$job = safeReadJson($jobPath, []);
if (empty($job)) {
    fwrite(STDERR, "Backup job record not found.\n");
    exit(2);
}

$writeJob = static function (array $updated) use ($jobPath): void {
    safeWriteJson($jobPath, $updated);
    @chmod($jobPath, 0600);
};

$appendLog = static function (array &$current, string $message, string $level = 'info'): void {
    $current['logs'][] = [
        'time' => date('H:i:s'),
        'level' => $level,
        'message' => $message
    ];
    if (count($current['logs']) > 2000) {
        $current['logs'] = array_slice($current['logs'], -2000);
    }
};

$job['status'] = 'running';
$job['stage'] = 'starting';
$job['started_at'] = date('Y-m-d H:i:s');
$appendLog($job, 'Worker online; initializing master database backup', 'system');
$writeJob($job);

try {
    $service = new BackupService($config['config_dir'] . '/databases.json', $config['runtime_dir']);
    $driveEnabled = $service->isGoogleDriveConfigured();
    $appendLog($job, $driveEnabled ? 'Destination: Google Drive and local backup archive' : 'Destination: local backup archive only; Google Drive credentials are not configured', $driveEnabled ? 'system' : 'warning');
    $writeJob($job);
    $summary = $service->runMasterBackup($job['triggered_by'] ?? 'system', $format, static function (array $event) use (&$job, $appendLog, $writeJob): void {
        $job['stage'] = $event['stage'] ?? $job['stage'];
        if (isset($event['total'])) {
            $job['total'] = (int)$event['total'];
        }
        if (isset($event['current'])) {
            $job['current'] = (int)$event['current'];
        }
        if (isset($event['database'])) {
            $job['current_database'] = (string)$event['database'];
        }
        if (in_array($job['stage'], ['database_complete', 'database_failed'], true)) {
            $job['completed'] = (int)($job['completed'] ?? 0) + 1;
        }
        $completed = (int)($job['completed'] ?? 0);
        $total = (int)($job['total'] ?? 0);
        $job['percent'] = $total > 0 ? min(100, (int)floor(($completed / $total) * 100)) : 0;
        $appendLog($job, (string)($event['message'] ?? $job['stage']), (string)($event['level'] ?? 'info'));
        $writeJob($job);
    });

    $job['summary'] = $summary;
    $job['status'] = $summary['failed'] > 0 ? 'completed_with_errors' : 'completed';
    $job['stage'] = 'complete';
    $job['current'] = (int)$summary['total'];
    $job['total'] = (int)$summary['total'];
    $job['percent'] = 100;
    $job['finished_at'] = date('Y-m-d H:i:s');
    $appendLog($job, sprintf('Run complete: %d succeeded, %d failed', $summary['successful'], $summary['failed']), $summary['failed'] > 0 ? 'warning' : 'success');
} catch (Throwable $error) {
    $job['status'] = 'failed';
    $job['stage'] = 'failed';
    $job['error'] = $error->getMessage();
    $job['finished_at'] = date('Y-m-d H:i:s');
    $appendLog($job, 'Backup stopped: ' . $error->getMessage(), 'error');
}

$writeJob($job);
exit($job['status'] === 'failed' ? 1 : 0);
