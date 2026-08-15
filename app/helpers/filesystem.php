<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Filesystem Helper
 * Secure path validation and atomic filesystem operations.
 */

if (!function_exists('isPathInsideDir')) {
    /**
     * Checks if a target path resolves safely inside an allowed base directory.
     * Prevents path traversal, symlink escapes, and directory escape.
     */
    function isPathInsideDir(string $targetPath, string $baseDir): bool
    {
        $realBase = realpath($baseDir);
        if ($realBase === false) {
            return false;
        }

        $realTarget = realpath($targetPath);
        if ($realTarget === false) {
            return false;
        }

        // Must strictly start with base directory path
        return strpos($realTarget, $realBase . DIRECTORY_SEPARATOR) === 0 || $realTarget === $realBase;
    }
}

if (!function_exists('safeReadJson')) {
    /**
     * Safely reads and parses a JSON file with file lock.
     */
    function safeReadJson(string $filePath, array $default = []): array
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            return $default;
        }

        $fp = @fopen($filePath, 'r');
        if (!$fp) {
            return $default;
        }

        @flock($fp, LOCK_SH);
        $content = @stream_get_contents($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        if ($content === false || trim($content) === '') {
            return $default;
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : $default;
    }
}

if (!function_exists('safeWriteJson')) {
    /**
     * Atomically writes data to a JSON file using temporary file swap and file lock.
     */
    function safeWriteJson(string $filePath, array $data): bool
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $tmpFile = $filePath . '.' . bin2hex(random_bytes(6)) . '.tmp';
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return false;
        }

        $fp = @fopen($tmpFile, 'c');
        if (!$fp) {
            return false;
        }

        if (!@flock($fp, LOCK_EX)) {
            @fclose($fp);
            @unlink($tmpFile);
            return false;
        }

        ftruncate($fp, 0);
        fwrite($fp, $json);
        fflush($fp);
        @flock($fp, LOCK_UN);
        @fclose($fp);

        return rename($tmpFile, $filePath);
    }
}

if (!function_exists('ensureDirExists')) {
    function ensureDirExists(string $dirPath, int $permissions = 0755): void
    {
        if (!is_dir($dirPath)) {
            mkdir($dirPath, $permissions, true);
        }
    }
}
