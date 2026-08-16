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
        return safeReadJson($this->configFile, ['databases' => []])['databases'] ?? [];
    }

    public function getDatabase(string $id): ?array
    {
        $dbs = $this->getDatabases();
        return $dbs[$id] ?? null;
    }

    public function saveDatabase(array $data): array
    {
        $dbs = $this->getDatabases();
        $id = $data['id'] ?? ('db_' . bin2hex(random_bytes(6)));

        $existing = $dbs[$id] ?? [];

        $dbs[$id] = [
            'id' => $id,
            'label' => trim($data['label'] ?? ($data['db_name'] ?? 'MySQL DB')),
            'db_host' => trim($data['db_host'] ?? '127.0.0.1'),
            'db_port' => (int)($data['db_port'] ?? 3306),
            'db_name' => trim($data['db_name'] ?? ''),
            'db_user' => trim($data['db_user'] ?? ''),
            'db_pass' => isset($data['db_pass']) && $data['db_pass'] !== '' ? $data['db_pass'] : ($existing['db_pass'] ?? ''),
            'schedule' => in_array($data['schedule'] ?? '', ['daily', '12h', '6h', 'weekly', 'disabled'], true) ? $data['schedule'] : 'daily',
            'schedule_time' => $data['schedule_time'] ?? '02:00',
            'retention_days' => max(1, (int)($data['retention_days'] ?? 7)),
            'last_backup_at' => $existing['last_backup_at'] ?? null,
            'created_at' => $existing['created_at'] ?? date('Y-m-d H:i:s')
        ];

        safeWriteJson($this->configFile, ['databases' => $dbs]);
        return $dbs[$id];
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

        // Secure MySQL dump using temporary defaults file
        $tempCnf = sys_get_temp_dir() . '/mysqldump_' . bin2hex(random_bytes(8)) . '.cnf';
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
                    'mysqldump --defaults-extra-file=%s %s %s 2>&1 | gzip > %s',
                    escapeshellarg($tempCnf),
                    $dumpFlags,
                    escapeshellarg($dbConfig['db_name']),
                    escapeshellarg($targetFile)
                );
            } else {
                $cmd = sprintf(
                    'mysqldump --defaults-extra-file=%s %s %s 2>&1 > %s',
                    escapeshellarg($tempCnf),
                    $dumpFlags,
                    escapeshellarg($dbConfig['db_name']),
                    escapeshellarg($targetFile)
                );
            }

            exec($cmd, $output, $returnVar);

            if (file_exists($tempCnf)) {
                @unlink($tempCnf);
            }

            if ($returnVar !== 0 || !file_exists($targetFile) || filesize($targetFile) === 0) {
                if (file_exists($targetFile)) {
                    @unlink($targetFile);
                }
                $errMsg = implode("\n", $output);
                throw new \RuntimeException("mysqldump failed (code {$returnVar}): " . ($errMsg ?: 'Unknown dump error. Check database credentials.'));
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
