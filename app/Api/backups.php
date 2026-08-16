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

        case 'delete_db':
            if (($currentUser['role'] ?? '') !== 'admin') {
                jsonError('FORBIDDEN', 'Only administrators can delete database configurations.', 403);
            }

            $dbId = trim($input['id'] ?? '');
            if (empty($dbId)) {
                jsonError('INVALID_INPUT', 'Database ID is required.', 400);
            }

            if (!$backupService->deleteDatabase($dbId)) {
                jsonError('NOT_FOUND', 'Database configuration not found.', 4404);
            }

            jsonSuccess(['message' => 'Database configuration deleted successfully.']);

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

        default:
            jsonError('INVALID_ACTION', "Unsupported action '{$postAction}'.", 400);
    }
}
