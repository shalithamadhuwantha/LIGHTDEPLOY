<?php
declare(strict_types=1);

namespace LightDeploy\Backup;

class BackupService
{
    private string $configFile;
    private string $storageDir;
    private int $defaultRetentionDays = 7;

    public function __construct(string $configFile, string $storageDir)
    {
        $this->configFile = $configFile;
        $this->storageDir = rtrim($storageDir, '/\\') . '/backups';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    public function getDatabases(): array
    {
        $dbs = safeReadJson($this->configFile, ['databases' => []])['databases'] ?? [];
        $cleaned = [];
        $needsMigration = false;

        foreach ($dbs as $k => $db) {
            $id = !empty($db['id']) ? (string)$db['id'] : (!empty($k) ? (string)$k : ('db_' . bin2hex(random_bytes(6))));
            if ($k !== $id || ($db['id'] ?? '') !== $id) {
                $db['id'] = $id;
                $needsMigration = true;
            }
            $cleaned[$id] = $db;
        }

        if ($needsMigration) {
            safeWriteJson($this->configFile, ['databases' => $cleaned]);
        }

        return $cleaned;
    }

    public function getDatabase(string $id): ?array
    {
        $dbs = $this->getDatabases();
        return $dbs[$id] ?? null;
    }

    public function saveDatabase(array $data): array
    {
        $dbs = $this->getDatabases();
        $id = !empty($data['id']) ? trim((string)$data['id']) : ('db_' . bin2hex(random_bytes(6)));

        if (isset($dbs[''])) {
            unset($dbs['']);
        }

        $existing = $dbs[$id] ?? [];

        $dbs[$id] = [
            'id' => $id,
            'label' => trim($data['label'] ?? ($data['db_name'] ?? 'MySQL DB')),
            'db_host' => trim($data['db_host'] ?? '127.0.0.1'),
            'db_port' => (int)($data['db_port'] ?? 3306),
            'db_name' => trim($data['db_name'] ?? ''),
            'db_user' => trim($data['db_user'] ?? ''),
            'db_pass' => isset($data['db_pass']) && $data['db_pass'] !== '' ? $data['db_pass'] : ($existing['db_pass'] ?? ''),
            'schedule' => in_array($data['schedule'] ?? '', ['5m', 'daily', '12h', '6h', 'weekly', 'disabled'], true) ? $data['schedule'] : 'daily',
            'schedule_time' => $data['schedule_time'] ?? '02:00',
            'retention_days' => max(1, (int)($data['retention_days'] ?? 7)),
            'last_backup_at' => $existing['last_backup_at'] ?? null,
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s')
        ];

        safeWriteJson($this->configFile, ['databases' => $dbs]);
        return $dbs[$id];
    }

    public function backupAllDatabases(string $triggeredBy = 'system', string $format = 'sql'): array
    {
        $dbs = $this->getDatabases();
        $successful = [];
        $errors = [];

        foreach ($dbs as $dbId => $dbConfig) {
            try {
                $successful[$dbId] = $this->runBackup($dbId, $triggeredBy, $format);
            } catch (\Throwable $e) {
                $errors[$dbId] = $e->getMessage();
            }
        }

        return [
            'total' => count($dbs),
            'successful' => count($successful),
            'failed' => count($errors),
            'details' => $successful,
            'errors' => $errors
        ];
    }

    public function setBulkSchedule(string $schedule): int
    {
        $validSchedules = ['5m', '6h', '12h', 'daily', 'weekly', 'disabled'];
        if (!in_array($schedule, $validSchedules, true)) {
            throw new \InvalidArgumentException("Invalid schedule value '{$schedule}'.");
        }

        $dbs = $this->getDatabases();
        $updatedCount = 0;

        foreach ($dbs as $dbId => &$dbConfig) {
            $dbConfig['schedule'] = $schedule;
            $updatedCount++;
        }
        unset($dbConfig);

        safeWriteJson($this->configFile, ['databases' => $dbs]);
        return $updatedCount;
    }

    public function deleteDatabase(string $id): bool
    {
        $dbs = $this->getDatabases();
        if (!isset($dbs[$id])) {
            return false;
        }

        unset($dbs[$id]);
        return safeWriteJson($this->configFile, ['databases' => $dbs]);
    }

    private function findMysqldumpBinary(): string
    {
        $candidates = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/www/server/mysql/bin/mysqldump',
            '/www/server/mariadb/bin/mysqldump',
            '/opt/lampp/bin/mysqldump'
        ];

        foreach ($candidates as $bin) {
            if (file_exists($bin) && is_executable($bin)) {
                return $bin;
            }
        }

        if (function_exists('safeShellExec')) {
            $whichPath = trim((string)safeShellExec('which mysqldump 2>/dev/null'));
            if (!empty($whichPath) && file_exists($whichPath)) {
                return $whichPath;
            }
        }

        return 'mysqldump';
    }

    public function runBackup(string $dbId, string $triggeredBy = 'system', string $format = 'sql'): array
    {
        $dbConfig = $this->getDatabase($dbId);
        if (!$dbConfig) {
            throw new \RuntimeException("Database configuration ID '{$dbId}' not found.");
        }

        $timestamp = date('Ymd_His');
        $cleanDbName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dbConfig['db_name']);
        $ext = ($format === 'sql.gz') ? 'sql.gz' : 'sql';
        $filename = "backup_{$dbId}_{$cleanDbName}_{$timestamp}.{$ext}";
        $targetFile = $this->storageDir . '/' . $filename;

        $mysqldumpBin = $this->findMysqldumpBinary();

        // Secure MySQL dump using temporary defaults file
        $tempCnf = sys_get_temp_dir() . '/mysqldump_' . bin2hex(random_bytes(8)) . '.cnf';
        $tempErr = sys_get_temp_dir() . '/mysqldump_err_' . bin2hex(random_bytes(8)) . '.log';

        $cnfContent = "[client]\n" .
            "host=" . escapeshellarg($dbConfig['db_host']) . "\n" .
            "port=" . (int)$dbConfig['db_port'] . "\n" .
            "user=" . escapeshellarg($dbConfig['db_user']) . "\n" .
            "password=" . escapeshellarg($dbConfig['db_pass']) . "\n";

        file_put_contents($tempCnf, $cnfContent);
        chmod($tempCnf, 0600);

        try {
            // Flags optimized specifically for phpMyAdmin import compatibility
            $dumpFlags = '--add-drop-table --add-locks --create-options --disable-keys --extended-insert --quick --set-charset --default-character-set=utf8mb4 --single-transaction --routines --triggers';
            
            if ($ext === 'sql.gz') {
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s %s %s 2> %s | gzip > %s',
                    escapeshellcmd($mysqldumpBin),
                    escapeshellarg($tempCnf),
                    $dumpFlags,
                    escapeshellarg($dbConfig['db_name']),
                    escapeshellarg($tempErr),
                    escapeshellarg($targetFile)
                );
            } else {
                $cmd = sprintf(
                    '%s --defaults-extra-file=%s %s %s --result-file=%s 2> %s',
                    escapeshellcmd($mysqldumpBin),
                    escapeshellarg($tempCnf),
                    $dumpFlags,
                    escapeshellarg($dbConfig['db_name']),
                    escapeshellarg($targetFile),
                    escapeshellarg($tempErr)
                );
            }

            $output = [];
            $returnVar = 0;
            safeExec($cmd, $output, $returnVar);

            $errLogContent = file_exists($tempErr) ? trim((string)file_get_contents($tempErr)) : '';
            if (file_exists($tempErr)) @unlink($tempErr);
            if (file_exists($tempCnf)) @unlink($tempCnf);

            // Filter out harmless password warning lines from mysqldump
            $criticalErrors = array_filter(explode("\n", $errLogContent), function($line) {
                $line = trim($line);
                return !empty($line) && !str_contains($line, '[Warning] Using a password');
            });

            if ($returnVar !== 0 || !file_exists($targetFile) || filesize($targetFile) === 0) {
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $errMsg = !empty($criticalErrors) ? implode("\n", $criticalErrors) : ($errLogContent ?: 'Check database host, port, user, and password credentials.');
                throw new \RuntimeException("MySQL Backup Failed: " . $errMsg);
            }

            // Inject phpMyAdmin-compatible transaction & foreign key checks header for plain .sql dumps
            if ($ext === 'sql') {
                $headerSql = "-- ==========================================================================\n" .
                    "-- LightDeploy MySQL Dump (phpMyAdmin Ready)\n" .
                    "-- Database: " . $dbConfig['db_name'] . "\n" .
                    "-- Host: " . $dbConfig['db_host'] . ":" . $dbConfig['db_port'] . "\n" .
                    "-- Date: " . date('Y-m-d H:i:s') . "\n" .
                    "-- ==========================================================================\n\n" .
                    "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n" .
                    "SET AUTOCOMMIT = 0;\n" .
                    "START TRANSACTION;\n" .
                    "SET time_zone = \"+00:00\";\n" .
                    "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                
                $footerSql = "\n\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n";

                $content = file_get_contents($targetFile);
                file_put_contents($targetFile, $headerSql . $content . $footerSql);
            }

            $filesize = filesize($targetFile);

            // Update last_backup_at timestamp
            $allDbs = $this->getDatabases();
            if (isset($allDbs[$dbId])) {
                $allDbs[$dbId]['last_backup_at'] = date('Y-m-d H:i:s');
                safeWriteJson($this->configFile, ['databases' => $allDbs]);
            }

            // Execute 7-day retention auto-pruning for this database
            $prunedFiles = $this->pruneOldBackups($dbId, (int)($dbConfig['retention_days'] ?? $this->defaultRetentionDays));

            return [
                'success' => true,
                'filename' => $filename,
                'filesize' => $filesize,
                'filesize_formatted' => $this->formatBytes($filesize),
                'created_at' => date('Y-m-d H:i:s'),
                'triggered_by' => $triggeredBy,
                'pruned_count' => count($prunedFiles)
            ];
        } catch (\Throwable $e) {
            if (file_exists($tempCnf)) {
                @unlink($tempCnf);
            }
            throw $e;
        }
    }

    public function pruneOldBackups(string $dbId, int $retentionDays = 7): array
    {
        $deleted = [];
        $cutoffTime = time() - ($retentionDays * 86400);

        $pattern = $this->storageDir . "/backup_{$dbId}_*.sql*";
        $files = glob($pattern) ?: [];

        foreach ($files as $file) {
            if (is_file($file) && filemtime($file) < $cutoffTime) {
                $basename = basename($file);
                if (@unlink($file)) {
                    $deleted[] = $basename;
                }
            }
        }

        return $deleted;
    }

    public function getBackupsForDb(string $dbId): array
    {
        $pattern = $this->storageDir . "/backup_{$dbId}_*.sql*";
        $files = glob($pattern) ?: [];

        $list = [];
        foreach ($files as $file) {
            if (!is_file($file)) continue;

            $size = filesize($file);
            $mtime = filemtime($file);
            $ageDays = round((time() - $mtime) / 86400, 1);

            $list[] = [
                'filename' => basename($file),
                'filesize' => $size,
                'filesize_formatted' => $this->formatBytes($size),
                'created_at' => date('Y-m-d H:i:s', $mtime),
                'age_days' => $ageDays,
                'is_expired' => $ageDays >= 7
            ];
        }

        usort($list, fn($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $list;
    }

    public function getAllBackups(): array
    {
        $dbs = $this->getDatabases();
        $result = [];

        foreach ($dbs as $dbId => $dbConfig) {
            $result[$dbId] = [
                'database' => $dbConfig,
                'backups' => $this->getBackupsForDb($dbId)
            ];
        }

        return $result;
    }

    public function deleteBackupFile(string $filename): bool
    {
        // Prevent directory traversal
        $safeName = basename($filename);
        $file = $this->storageDir . '/' . $safeName;

        if (file_exists($file) && is_file($file)) {
            return @unlink($file);
        }

        return false;
    }

    public function streamDownload(string $filename): void
    {
        $safeName = basename($filename);
        $file = $this->storageDir . '/' . $safeName;

        if (!file_exists($file) || !is_file($file)) {
            http_response_code(404);
            echo "Backup file not found.";
            exit;
        }

        $contentType = str_ends_with($safeName, '.sql') ? 'application/sql' : 'application/gzip';

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $contentType);
        header('Content-Disposition: attachment; filename="' . $safeName . '"');
        header('Content-Length: ' . filesize($file));
        header('Pragma: public');
        header('Expires: 0');
        header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

        readfile($file);
        exit;
    }

    public function getMasterCredentials(): array
    {
        $masterFile = dirname($this->configFile) . '/master_db.json';
        $data = safeReadJson($masterFile, [
            'enabled' => false,
            'db_host' => '127.0.0.1',
            'db_port' => 3306,
            'db_user' => 'root',
            'db_pass' => '',
            'last_tested_at' => null
        ]);
        return $data;
    }

    public function saveMasterCredentials(array $data): array
    {
        $existing = $this->getMasterCredentials();
        $masterFile = dirname($this->configFile) . '/master_db.json';

        $updated = [
            'enabled' => !empty($data['enabled']),
            'db_host' => trim($data['db_host'] ?? '127.0.0.1'),
            'db_port' => (int)($data['db_port'] ?? 3306),
            'db_user' => trim($data['db_user'] ?? 'root'),
            'db_pass' => (isset($data['db_pass']) && $data['db_pass'] !== '') ? $data['db_pass'] : ($existing['db_pass'] ?? ''),
            'last_tested_at' => $existing['last_tested_at'] ?? null,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        safeWriteJson($masterFile, $updated);
        return $updated;
    }

    public function testMasterConnection(?array $creds = null): array
    {
        if ($creds === null) {
            $creds = $this->getMasterCredentials();
        }

        $host = trim($creds['db_host'] ?? '127.0.0.1');
        $port = (int)($creds['db_port'] ?? 3306);
        $user = trim($creds['db_user'] ?? 'root');
        $pass = $creds['db_pass'] ?? '';

        if (empty($user)) {
            throw new \InvalidArgumentException('Master database username cannot be empty.');
        }

        $dsn = sprintf("mysql:host=%s;port=%d;charset=utf8mb4", $host, $port);
        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_TIMEOUT => 5
        ];

        $pdo = new \PDO($dsn, $user, $pass, $options);
        $stmt = $pdo->query("SHOW DATABASES;");
        $allDbs = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $systemDbs = ['information_schema', 'performance_schema', 'mysql', 'sys'];
        $userDbs = array_values(array_filter($allDbs, function ($db) use ($systemDbs) {
            return !in_array(strtolower($db), $systemDbs, true);
        }));

        $masterFile = dirname($this->configFile) . '/master_db.json';
        if (file_exists($masterFile)) {
            $current = safeReadJson($masterFile, []);
            if (!empty($current)) {
                $current['last_tested_at'] = date('Y-m-d H:i:s');
                safeWriteJson($masterFile, $current);
            }
        }

        return [
            'success' => true,
            'host' => $host,
            'port' => $port,
            'user' => $user,
            'total_databases' => count($allDbs),
            'user_databases' => $userDbs,
            'user_database_count' => count($userDbs)
        ];
    }

    public function runMasterBackup(string $triggeredBy = 'system', string $format = 'sql'): array
    {
        $creds = $this->getMasterCredentials();
        if (empty($creds['db_user'])) {
            throw new \RuntimeException('Master database credentials are not configured.');
        }

        $testResult = $this->testMasterConnection($creds);
        $userDbs = $testResult['user_databases'] ?? [];

        if (empty($userDbs)) {
            throw new \RuntimeException('No user databases found using Master credentials.');
        }

        $dbsMap = [];
        foreach ($this->getDatabases() as $id => $cfg) {
            if (!empty($cfg['db_name'])) {
                $dbsMap[$cfg['db_name']] = $id;
            }
        }

        $mysqldumpBin = $this->findMysqldumpBinary();
        $timestamp = date('Ymd_His');
        $ext = ($format === 'sql.gz') ? 'sql.gz' : 'sql';

        $tempCnf = sys_get_temp_dir() . '/mysqldump_master_' . bin2hex(random_bytes(8)) . '.cnf';
        $cnfContent = "[client]\n" .
            "host=" . escapeshellarg($creds['db_host']) . "\n" .
            "port=" . (int)$creds['db_port'] . "\n" .
            "user=" . escapeshellarg($creds['db_user']) . "\n" .
            "password=" . escapeshellarg($creds['db_pass']) . "\n";

        file_put_contents($tempCnf, $cnfContent);
        chmod($tempCnf, 0600);

        $successful = [];
        $errors = [];

        try {
            $dumpFlags = '--add-drop-table --add-locks --create-options --disable-keys --extended-insert --quick --set-charset --default-character-set=utf8mb4 --single-transaction --routines --triggers';

            foreach ($userDbs as $dbName) {
                $cleanDbName = preg_replace('/[^a-zA-Z0-9_\-]/', '', $dbName);
                $filename = "master_backup_{$cleanDbName}_{$timestamp}.{$ext}";
                $targetFile = $this->storageDir . '/' . $filename;
                $tempErr = sys_get_temp_dir() . '/mysqldump_master_err_' . bin2hex(random_bytes(8)) . '.log';

                if ($ext === 'sql.gz') {
                    $cmd = sprintf(
                        '%s --defaults-extra-file=%s %s %s 2> %s | gzip > %s',
                        escapeshellcmd($mysqldumpBin),
                        escapeshellarg($tempCnf),
                        $dumpFlags,
                        escapeshellarg($dbName),
                        escapeshellarg($tempErr),
                        escapeshellarg($targetFile)
                    );
                } else {
                    $cmd = sprintf(
                        '%s --defaults-extra-file=%s %s %s --result-file=%s 2> %s',
                        escapeshellcmd($mysqldumpBin),
                        escapeshellarg($tempCnf),
                        $dumpFlags,
                        escapeshellarg($dbName),
                        escapeshellarg($targetFile),
                        escapeshellarg($tempErr)
                    );
                }

                $output = [];
                $returnVar = 0;
                safeExec($cmd, $output, $returnVar);

                if (file_exists($tempErr)) @unlink($tempErr);

                if ($returnVar === 0 && file_exists($targetFile) && filesize($targetFile) > 0) {
                    if ($ext === 'sql') {
                        $headerSql = "-- ==========================================================================\n" .
                            "-- LightDeploy Master Dump (phpMyAdmin Ready)\n" .
                            "-- Database: " . $dbName . "\n" .
                            "-- Triggered By: Master User (" . $creds['db_user'] . ")\n" .
                            "-- Date: " . date('Y-m-d H:i:s') . "\n" .
                            "-- ==========================================================================\n\n" .
                            "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n" .
                            "SET AUTOCOMMIT = 0;\n" .
                            "START TRANSACTION;\n" .
                            "SET time_zone = \"+00:00\";\n" .
                            "SET FOREIGN_KEY_CHECKS = 0;\n\n";
                        $footerSql = "\n\nSET FOREIGN_KEY_CHECKS = 1;\nCOMMIT;\n";
                        $content = file_get_contents($targetFile);
                        file_put_contents($targetFile, $headerSql . $content . $footerSql);
                    }

                    $successful[$dbName] = [
                        'filename' => $filename,
                        'filesize' => filesize($targetFile),
                        'filesize_formatted' => $this->formatBytes(filesize($targetFile)),
                        'created_at' => date('Y-m-d H:i:s'),
                        'db_name' => $dbName
                    ];
                } else {
                    $errors[$dbName] = "mysqldump failed for database '{$dbName}'.";
                }
            }
        } finally {
            if (file_exists($tempCnf)) @unlink($tempCnf);
        }

        return [
            'total' => count($userDbs),
            'successful' => count($successful),
            'failed' => count($errors),
            'details' => $successful,
            'errors' => $errors
        ];
    }

    public function getMasterBackupHistory(): array
    {
        $files = glob($this->storageDir . '/master_backup_*.sql*') ?: [];
        $sessions = [];

        foreach ($files as $file) {
            if (!is_file($file)) continue;

            $filename = basename($file);
            $size = filesize($file);
            $mtime = filemtime($file);

            $timestampKey = date('Y-m-d H:i:00', $mtime);
            if (preg_match('/(\d{8}_\d{6})\.sql/', $filename, $matches)) {
                $dt = \DateTime::createFromFormat('Ymd_His', $matches[1]);
                if ($dt) {
                    $timestampKey = $dt->format('Y-m-d H:i:s');
                }
            }

            $dbName = 'Database';
            if (preg_match('/master_backup_([a-zA-Z0-9_\-]+)_\d{8}_\d{6}/', $filename, $dbMatches)) {
                $dbName = $dbMatches[1];
            } else {
                $parts = explode('_', $filename);
                if (count($parts) >= 3) {
                    $dbName = $parts[count($parts) - 3];
                }
            }

            if (!isset($sessions[$timestampKey])) {
                $sessions[$timestampKey] = [
                    'session_time' => $timestampKey,
                    'timestamp_label' => date('M d, Y - h:i:s A', strtotime($timestampKey)),
                    'total_size' => 0,
                    'total_files' => 0,
                    'files' => []
                ];
            }

            $sessions[$timestampKey]['total_size'] += $size;
            $sessions[$timestampKey]['total_files'] += 1;
            $sessions[$timestampKey]['files'][] = [
                'filename' => $filename,
                'db_name' => $dbName,
                'filesize' => $size,
                'filesize_formatted' => $this->formatBytes($size),
                'created_at' => date('Y-m-d H:i:s', $mtime),
                'age_days' => round((time() - $mtime) / 86400, 1)
            ];
        }

        foreach ($sessions as &$session) {
            $session['total_size_formatted'] = $this->formatBytes($session['total_size']);
            usort($session['files'], fn($a, $b) => strcmp($a['db_name'], $b['db_name']));
        }
        unset($session);

        krsort($sessions);
        return array_values($sessions);
    }

    private function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}
