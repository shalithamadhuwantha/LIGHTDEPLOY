<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API - MySQL Database Backup Suite Manager
 * Handles database credential management, 1-Click backups, retention pruning, and file downloads.
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Backup\BackupService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');
if (!$authService->isAuthenticated()) {
    jsonError('UNAUTHORIZED', 'Authentication required.', 401);
}

$currentUser = $authService->getCurrentUser();
$backupService = new BackupService(
    $config['config_dir'] . '/databases.json',
    $config['runtime_dir']
);

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
            unset($creds['db_pass']);
            $creds['has_password'] = !empty($backupService->getMasterCredentials()['db_pass']);
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
                    'db_pass' => $input['db_pass'] ?? ''
                ]);
                unset($saved['db_pass']);
                jsonSuccess([
                    'message' => 'Master MySQL credentials saved successfully!',
                    'master_credentials' => $saved
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
            if (!in_array($currentUser['role'] ?? '', ['admin', 'deployer'], true)) {
                jsonError('FORBIDDEN', 'Insufficient permissions to run Master backup.', 403);
            }

            $format = trim($input['format'] ?? 'sql');
            try {
                $summary = $backupService->runMasterBackup($currentUser['username'] ?? 'operator', $format);
                jsonSuccess([
                    'message' => sprintf(
                        'Master Dump Complete: Successfully backed up %d/%d VPS databases into separate phpMyAdmin-ready .%s files!',
                        $summary['successful'],
                        $summary['total'],
                        $format
                    ),
                    'summary' => $summary
                ]);
            } catch (\Throwable $e) {
                jsonError('MASTER_BACKUP_FAILED', $e->getMessage(), 500);
            }
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
