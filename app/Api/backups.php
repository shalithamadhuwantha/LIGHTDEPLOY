<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API - MySQL Database Backup Suite Manager
 * Handles database credential management, 1-Click backups, retention pruning, and file downloads.
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Backup\BackupService;
use LightDeploy\Auth\Csrf;

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--master-backup-worker') {
    $jobId = trim((string)($argv[2] ?? ''));
    $format = trim((string)($argv[3] ?? 'sql'));
    if (!preg_match('/^MBK-\d{14}-[a-f0-9]{10}$/', $jobId)) {
        fwrite(STDERR, "Invalid backup job ID.\n");
        exit(2);
    }

    $jobPath = $config['runtime_dir'] . '/master_backup_jobs/' . $jobId . '.json';
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
        $current['logs'][] = ['time' => date('H:i:s'), 'level' => $level, 'message' => $message];
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
        $localFolder = trim((string)($service->getMasterCredentials()['local_backup_folder'] ?? ''));
        if ($localFolder !== '') {
            $appendLog($job, 'Destination: local folder ' . $localFolder, 'system');
        }
        $writeJob($job);
        $summary = $service->runMasterBackup($job['triggered_by'] ?? 'system', $format, static function (array $event) use (&$job, $appendLog, $writeJob): void {
            $job['stage'] = $event['stage'] ?? $job['stage'];
            if (isset($event['total'])) $job['total'] = (int)$event['total'];
            if (isset($event['current'])) $job['current'] = (int)$event['current'];
            if (isset($event['database'])) $job['current_database'] = (string)$event['database'];
            if (in_array($job['stage'], ['database_complete', 'database_failed'], true)) {
                $job['completed'] = (int)($job['completed'] ?? 0) + 1;
            }
            $total = (int)($job['total'] ?? 0);
            $job['percent'] = $total > 0 ? min(100, (int)floor(((int)($job['completed'] ?? 0) / $total) * 100)) : 0;
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
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';
$isGoogleOAuthCallback = $method === 'GET' && $action === 'google_oauth_callback';

if (!$isGoogleOAuthCallback) {
    $authService->requirePermission('db_backups');
    $currentUser = $authService->getCurrentUser();
} else {
    $currentUser = [];
}
$backupService = new BackupService(
    $config['config_dir'] . '/databases.json',
    $config['runtime_dir']
);

$startMasterBackupJob = static function (string $triggeredBy, string $format = 'sql') use ($config): array {
    $jobsDir = $config['runtime_dir'] . '/master_backup_jobs';
    ensureDirExists($jobsDir, 0700);
    $jobId = 'MBK-' . date('YmdHis') . '-' . bin2hex(random_bytes(5));
    $jobPath = $jobsDir . '/' . $jobId . '.json';
    $job = [
        'job_id' => $jobId,
        'status' => 'queued',
        'stage' => 'queued',
        'current' => 0,
        'total' => 0,
        'percent' => 0,
        'triggered_by' => $triggeredBy,
        'started_at' => date('Y-m-d H:i:s'),
        'logs' => [['time' => date('H:i:s'), 'level' => 'system', 'message' => 'Backup job queued; launching worker']]
    ];
    if (!safeWriteJson($jobPath, $job)) {
        throw new RuntimeException('Could not create the master backup job record.');
    }
    @chmod($jobPath, 0600);

    $workerPath = __FILE__;

    $phpCli = PHP_BINARY;
    if (!preg_match('/^php(?:[0-9.]*)?$/i', basename($phpCli))) {
        $candidates = [PHP_BINDIR . '/php', '/usr/bin/php', '/usr/local/bin/php'];
        $phpCli = '';
        foreach ($candidates as $candidate) {
            if (is_executable($candidate)) {
                $phpCli = $candidate;
                break;
            }
        }
        if ($phpCli === '') {
            $phpCli = trim((string)safeShellExec('command -v php 2>/dev/null'));
        }
    }
    if ($phpCli === '' || !is_executable($phpCli)) {
        throw new RuntimeException('Could not locate an executable PHP CLI binary for the backup worker.');
    }

    $command = sprintf(
        'nohup %s %s %s %s > /dev/null 2>&1 &',
        escapeshellarg($phpCli),
        escapeshellarg($workerPath),
        escapeshellarg('--master-backup-worker'),
        escapeshellarg($jobId),
        escapeshellarg($format)
    );
    $spawned = false;
    if (isFunctionAvailable('proc_open')) {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'a'],
            2 => ['file', '/dev/null', 'a']
        ];
        $process = @proc_open(['/bin/sh', '-c', $command], $descriptors, $pipes);
        if (is_resource($process)) {
            $spawned = proc_close($process) === 0;
        }
    }
    if (!$spawned && safeShellExec($command) !== null) {
        $spawned = true;
    }

    if (!$spawned) {
        $job['status'] = 'failed';
        $job['stage'] = 'failed';
        $job['finished_at'] = date('Y-m-d H:i:s');
        $job['error'] = 'Could not launch the PHP backup worker. Enable proc_open or shell execution for the web PHP runtime.';
        $job['logs'][] = ['time' => date('H:i:s'), 'level' => 'error', 'message' => $job['error']];
        safeWriteJson($jobPath, $job);
        throw new RuntimeException((string)$job['error']);
    }

    return ['job_id' => $jobId, 'status' => 'queued'];
};

if ($method === 'GET' && $action === 'google_oauth_callback') {
    $settings = $backupService->getMasterCredentials();
    $redirectUri = (string)($settings['google_oauth_redirect_uri'] ?? '');
    $redirectParts = parse_url($redirectUri);
    $origin = is_array($redirectParts) && !empty($redirectParts['scheme']) && !empty($redirectParts['host'])
        ? $redirectParts['scheme'] . '://' . $redirectParts['host'] . (isset($redirectParts['port']) ? ':' . $redirectParts['port'] : '')
        : '';
    $sendOAuthResult = static function (bool $success, string $message, array $extra = []) use ($origin): never {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store');
        }
        $event = json_encode(array_merge(['type' => 'lightdeploy-google-drive-oauth', 'success' => $success, 'message' => $message], $extra), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        $safeMessage = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $targetOrigin = json_encode($origin, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
        echo '<!doctype html><html lang="en"><meta charset="utf-8"><title>Google Drive connection</title><body><p>' . $safeMessage . '</p><script>if(window.opener){window.opener.postMessage(' . $event . ',' . $targetOrigin . ');window.close();}</script></body></html>';
        exit;
    };

    $returnedState = (string)($_GET['state'] ?? '');
    $stateFile = $config['runtime_dir'] . '/google_oauth_states/' . hash('sha256', $returnedState) . '.json';
    $oauthState = $returnedState !== '' ? safeReadJson($stateFile, []) : [];
    if (is_file($stateFile)) {
        @unlink($stateFile);
    }
    if (!is_array($oauthState) || empty($oauthState['state']) || (int)($oauthState['created_at'] ?? 0) < time() - 600 || !hash_equals((string)$oauthState['state'], $returnedState)) {
        $sendOAuthResult(false, 'Google authorization state was invalid or expired. Start the connection again.');
    }
    if (!empty($_GET['error'])) {
        $sendOAuthResult(false, 'Google authorization was not completed: ' . (string)$_GET['error']);
    }
    if (empty($_GET['code']) || empty($settings['google_oauth_client_id']) || empty($settings['google_oauth_client_secret']) || $redirectUri === '') {
        $sendOAuthResult(false, 'Google OAuth settings or authorization code are missing.');
    }

    $curl = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'code' => (string)$_GET['code'],
            'client_id' => $settings['google_oauth_client_id'],
            'client_secret' => $settings['google_oauth_client_secret'],
            'redirect_uri' => $redirectUri
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);
    $tokenResponse = curl_exec($curl);
    $tokenStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $tokenError = curl_error($curl);
    curl_close($curl);
    $tokenData = is_string($tokenResponse) ? json_decode($tokenResponse, true) : null;
    if ($tokenStatus !== 200 || empty($tokenData['access_token'])) {
        $message = $tokenData['error_description'] ?? $tokenData['error'] ?? $tokenError ?: 'HTTP ' . $tokenStatus;
        $sendOAuthResult(false, 'Could not exchange Google authorization code: ' . $message);
    }
    $refreshToken = $tokenData['refresh_token'] ?? ($settings['google_oauth_refresh_token'] ?? '');
    if ($refreshToken === '') {
        $sendOAuthResult(false, 'Google did not provide a refresh token. Remove this app from your Google Account security settings, then connect again and approve offline access.');
    }

    try {
        $backupService->saveMasterCredentials([
            'google_oauth_client_id' => $settings['google_oauth_client_id'],
            'google_oauth_client_secret' => $settings['google_oauth_client_secret'],
            'google_oauth_redirect_uri' => $redirectUri,
            'google_oauth_refresh_token' => $refreshToken
        ]);
        $job = $startMasterBackupJob((string)($oauthState['triggered_by'] ?? 'admin'), 'sql');
        $sendOAuthResult(true, 'Personal Google Drive connected. Starting the master database backup now.', ['job_id' => $job['job_id']]);
    } catch (\Throwable $e) {
        $sendOAuthResult(false, 'Google account connected, but the backup could not start: ' . $e->getMessage());
    }
}

// Handle direct GZIP file download stream
if ($method === 'GET' && $action === 'download') {
    $filename = $_GET['filename'] ?? '';
    if (empty($filename)) {
        jsonError('INVALID_INPUT', 'Filename is required for download.', 400);
    }
    $backupService->streamDownload($filename);
    exit;
}

if ($method === 'GET') {
    $dbs = $backupService->getDatabases();
    
    // Mask passwords for API response
    $safeDbs = [];
    foreach ($dbs as $id => $db) {
        $dbCopy = $db;
        $dbCopy['db_pass'] = !empty($db['db_pass']) ? '********' : '';
        $dbCopy['backups'] = $backupService->getBackupsForDb($id);
        $safeDbs[$id] = $dbCopy;
    }

    jsonSuccess([
        'databases' => $safeDbs,
        'total_databases' => count($safeDbs),
        'timestamp' => date('Y-m-d H:i:s')
    ]);
}

if ($method === 'POST') {
    if (!Csrf::validateHeaderOrPost()) {
        jsonError('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }

    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
    $postAction = $input['action'] ?? '';

    switch ($postAction) {
        case 'google_oauth_start':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can connect Google Drive.', 403);
            }

            try {
                $settings = $backupService->saveMasterCredentials([
                    'enabled' => !empty($input['enabled']),
                    'db_host' => $input['db_host'] ?? '127.0.0.1',
                    'db_port' => (int)($input['db_port'] ?? 3306),
                    'db_user' => $input['db_user'] ?? 'root',
                    'db_pass' => $input['db_pass'] ?? '',
                    'google_service_account_json' => $input['google_service_account_json'] ?? '',
                    'google_drive_folder_id' => $input['google_drive_folder_id'] ?? '',
                    'google_oauth_client_id' => $input['google_oauth_client_id'] ?? '',
                    'google_oauth_client_secret' => $input['google_oauth_client_secret'] ?? '',
                    'google_oauth_redirect_uri' => $input['google_oauth_redirect_uri'] ?? '',
                    'local_backup_folder' => $input['local_backup_folder'] ?? ''
                ]);
                if (empty($settings['google_oauth_client_id']) || empty($settings['google_oauth_client_secret']) || empty($settings['google_oauth_redirect_uri'])) {
                    jsonError('OAUTH_SETTINGS_REQUIRED', 'Enter the Google OAuth client ID, client secret, and redirect URI first.', 400);
                }

                $state = bin2hex(random_bytes(32));
                $stateDir = $config['runtime_dir'] . '/google_oauth_states';
                ensureDirExists($stateDir, 0700);
                $statePath = $stateDir . '/' . hash('sha256', $state) . '.json';
                if (!safeWriteJson($statePath, [
                    'state' => $state,
                    'created_at' => time(),
                    'triggered_by' => $currentUser['username'] ?? 'admin'
                ])) {
                    throw new RuntimeException('Could not create Google OAuth state record.');
                }
                @chmod($statePath, 0600);
                $authorizationUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
                    'client_id' => $settings['google_oauth_client_id'],
                    'redirect_uri' => $settings['google_oauth_redirect_uri'],
                    'response_type' => 'code',
                    'scope' => 'https://www.googleapis.com/auth/drive',
                    'access_type' => 'offline',
                    'prompt' => 'consent',
                    'include_granted_scopes' => 'true',
                    'state' => $state
                ]);
                jsonSuccess(['authorization_url' => $authorizationUrl]);
            } catch (\Throwable $e) {
                jsonError('GOOGLE_OAUTH_START_FAILED', $e->getMessage(), 400);
            }
            break;

        case 'save_db':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to save database configuration.', 403);
            }

            $dbName = trim($input['db_name'] ?? '');
            if (empty($dbName)) {
                jsonError('INVALID_INPUT', 'Database Name is required.', 400);
            }

            $savedDb = $backupService->saveDatabase($input);
            $savedDb['db_pass'] = '********';

            jsonSuccess([
                'message' => 'Database configuration saved successfully.',
                'database' => $savedDb
            ]);
            break;

        case 'delete_db':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can delete database configurations.', 403);
            }

            $dbId = trim($input['id'] ?? '');
            if (empty($dbId)) {
                jsonError('INVALID_INPUT', 'Database ID is required.', 400);
            }

            if (!$backupService->deleteDatabase($dbId)) {
                jsonError('NOT_FOUND', 'Database configuration not found.', 404);
            }

            jsonSuccess(['message' => 'Database configuration deleted successfully.']);
            break;

        case 'run_backup':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to trigger database backup.', 403);
            }

            $dbId = trim($input['id'] ?? '');
            $format = trim($input['format'] ?? 'sql');
            if (empty($dbId)) {
                jsonError('INVALID_INPUT', 'Database ID is required to execute backup.', 400);
            }

            try {
                $result = $backupService->runBackup($dbId, $currentUser['username'] ?? 'operator', $format);
                jsonSuccess([
                    'message' => 'Database backup (.sql format, phpMyAdmin compatible) executed successfully!',
                    'result' => $result
                ]);
            } catch (\Throwable $e) {
                jsonError('BACKUP_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'backup_all':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to trigger database backup.', 403);
            }

            $format = trim($input['format'] ?? 'sql');
            try {
                $summary = $backupService->backupAllDatabases($currentUser['username'] ?? 'operator', $format);
                jsonSuccess([
                    'message' => sprintf(
                        'Successfully backed up %d/%d databases into separate phpMyAdmin-compatible .%s files!',
                        $summary['successful'],
                        $summary['total'],
                        $format
                    ),
                    'summary' => $summary
                ]);
            } catch (\Throwable $e) {
                jsonError('BACKUP_ALL_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'bulk_schedule':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can update global database backup schedules.', 403);
            }

            $schedule = trim($input['schedule'] ?? 'daily');
            try {
                $count = $backupService->setBulkSchedule($schedule);
                jsonSuccess([
                    'message' => sprintf('Successfully updated schedule to \'%s\' for all %d configured databases.', $schedule, $count),
                    'updated_count' => $count
                ]);
            } catch (\InvalidArgumentException $e) {
                jsonError('INVALID_INPUT', $e->getMessage(), 400);
            } catch (\Throwable $e) {
                jsonError('BULK_SCHEDULE_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'get_master_creds':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can access Master DB credentials.', 403);
            }

            $creds = $backupService->getMasterCredentials();
            $serviceAccount = json_decode($creds['google_service_account_json'] ?? '', true);
            unset($creds['db_pass']);
            unset($creds['google_service_account_json']);
            unset($creds['google_oauth_client_secret']);
            unset($creds['google_oauth_refresh_token']);
            $creds['has_password'] = !empty($backupService->getMasterCredentials()['db_pass']);
            $creds['has_google_drive_credentials'] = $backupService->isGoogleDriveConfigured();
            $creds['has_service_account_credentials'] = is_array($serviceAccount) && !empty($serviceAccount['client_email']) && !empty($serviceAccount['private_key']);
            $creds['has_google_oauth_client'] = !empty($creds['google_oauth_client_id']) && !empty($creds['google_oauth_redirect_uri']);
            $creds['google_drive_connected'] = !empty($backupService->getMasterCredentials()['google_oauth_refresh_token']);
            jsonSuccess(['master_credentials' => $creds]);
            break;

        case 'save_master_creds':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can update Master DB credentials.', 403);
            }

            try {
                $saved = $backupService->saveMasterCredentials([
                    'enabled' => !empty($input['enabled']),
                    'db_host' => $input['db_host'] ?? '127.0.0.1',
                    'db_port' => (int)($input['db_port'] ?? 3306),
                    'db_user' => $input['db_user'] ?? 'root',
                    'db_pass' => $input['db_pass'] ?? '',
                    'google_service_account_json' => $input['google_service_account_json'] ?? '',
                    'google_drive_folder_id' => $input['google_drive_folder_id'] ?? '',
                    'google_oauth_client_id' => $input['google_oauth_client_id'] ?? '',
                    'google_oauth_client_secret' => $input['google_oauth_client_secret'] ?? '',
                    'google_oauth_redirect_uri' => $input['google_oauth_redirect_uri'] ?? '',
                    'local_backup_folder' => $input['local_backup_folder'] ?? ''
                ]);
                unset($saved['db_pass']);
                unset($saved['google_service_account_json']);
                unset($saved['google_oauth_client_secret']);
                unset($saved['google_oauth_refresh_token']);
                $backupSummary = null;
                $backupJob = null;
                if ($backupService->isGoogleDriveConfigured()) {
                    try {
                        $backupJob = $startMasterBackupJob($currentUser['username'] ?? 'operator', 'sql');
                    } catch (\Throwable $e) {
                        $backupSummary = [
                            'total' => 0,
                            'successful' => 0,
                            'failed' => 1,
                            'details' => [],
                            'errors' => ['_backup' => $e->getMessage()]
                        ];
                    }
                }
                jsonSuccess([
                    'message' => $backupSummary === null
                        ? ($backupJob !== null
                            ? 'Master credentials saved; Google Drive backup started.'
                            : (!empty($saved['google_oauth_client_id'])
                                ? 'Master credentials saved. Connect your personal Google Drive to enable uploads.'
                                : 'Master MySQL credentials saved. Configure Google Drive authorization to enable uploads.'))
                        : ($backupSummary['total'] === 0
                            ? 'Master credentials saved, but the automatic Google Drive backup could not start: ' . ($backupSummary['errors']['_backup'] ?? 'unknown error')
                            : sprintf('Master credentials saved; Google Drive backup completed for %d/%d databases (%d failed).', $backupSummary['successful'], $backupSummary['total'], $backupSummary['failed'])),
                    'master_credentials' => $saved,
                    'backup_summary' => $backupSummary,
                    'backup_job' => $backupJob
                ]);
            } catch (\Throwable $e) {
                jsonError('SAVE_MASTER_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'test_master_creds':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to test database credentials.', 403);
            }

            $testCreds = null;
            if (!empty($input['db_user'])) {
                $saved = $backupService->getMasterCredentials();
                $testCreds = [
                    'db_host' => $input['db_host'] ?? '127.0.0.1',
                    'db_port' => (int)($input['db_port'] ?? 3306),
                    'db_user' => $input['db_user'] ?? 'root',
                    'db_pass' => ($input['db_pass'] !== '') ? $input['db_pass'] : ($saved['db_pass'] ?? '')
                ];
            }

            try {
                $res = $backupService->testMasterConnection($testCreds);
                jsonSuccess([
                    'message' => sprintf('Connection successful! Found %d user databases on VPS via Master user \'%s\'.', $res['user_database_count'], $res['user']),
                    'result' => $res
                ]);
            } catch (\Throwable $e) {
                jsonError('TEST_MASTER_FAILED', 'Connection failed: ' . $e->getMessage(), 400);
            }
            break;

        case 'run_master_backup':
        case 'start_master_backup':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to run Master backup.', 403);
            }

            $format = trim($input['format'] ?? 'sql');
            try {
                $job = $startMasterBackupJob($currentUser['username'] ?? 'operator', $format);
                jsonSuccess([
                    'message' => 'Master database backup job started.',
                    'job' => $job
                ]);
            } catch (\Throwable $e) {
                jsonError('MASTER_BACKUP_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'master_backup_status':
            $jobId = trim((string)($input['job_id'] ?? ''));
            if (!preg_match('/^MBK-\d{14}-[a-f0-9]{10}$/', $jobId)) {
                jsonError('INVALID_INPUT', 'Invalid master backup job ID.', 400);
            }
            $jobPath = $config['runtime_dir'] . '/master_backup_jobs/' . $jobId . '.json';
            if (!is_file($jobPath)) {
                jsonError('NOT_FOUND', 'Master backup job was not found.', 404);
            }
            jsonSuccess(['job' => safeReadJson($jobPath, [])]);
            break;

        case 'get_master_history':
            try {
                $sessions = $backupService->getMasterBackupHistory();
                jsonSuccess(['sessions' => $sessions]);
            } catch (\Throwable $e) {
                jsonError('MASTER_HISTORY_FAILED', $e->getMessage(), 500);
            }
            break;

        case 'delete_backup':
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to delete backup files.', 403);
            }

            $filename = trim($input['filename'] ?? '');
            if (empty($filename)) {
                jsonError('INVALID_INPUT', 'Filename is required.', 400);
            }

            if (!$backupService->deleteBackupFile($filename)) {
                jsonError('NOT_FOUND', 'Backup file not found or could not be deleted.', 404);
            }

            jsonSuccess(['message' => "Backup file '{$filename}' deleted successfully."]);
            break;

        default:
            jsonError('INVALID_ACTION', "Unsupported action '{$postAction}'.", 400);
    }
}
