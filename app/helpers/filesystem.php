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
    function safeReadJson(string $filePath, ?array $default = []): array
    {
        $default = $default ?? [];
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

if (!function_exists('isFunctionAvailable')) {
    /**
     * Checks if a PHP function is both defined AND not disabled in php.ini.
     * function_exists() alone returns true for disabled functions in PHP 8+,
     * which causes fatal uncaught Error exceptions when the function is called.
     */
    function isFunctionAvailable(string $func): bool
    {
        if (!function_exists($func)) {
            return false;
        }
        $disabled = ini_get('disable_functions');
        if ($disabled === false || $disabled === '') {
            return true;
        }
        $disabledList = array_map('trim', explode(',', strtolower($disabled)));
        return !in_array(strtolower($func), $disabledList, true);
    }
}

if (!function_exists('safeShellExec')) {
    /**
     * Safely executes a shell command with automatic fallback cascading across available PHP execution mechanisms.
     * Prevents fatal uncaught errors when shell_exec/exec/proc_open are disabled in php.ini.
     */
    function safeShellExec(string $command): ?string
    {
        // 1. Try shell_exec
        if (isFunctionAvailable('shell_exec')) {
            try {
                $res = @shell_exec($command);
                if ($res !== false && $res !== null) {
                    return $res;
                }
            } catch (\Throwable $e) {}
        }

        // 2. Try exec
        if (isFunctionAvailable('exec')) {
            try {
                $output = [];
                $returnVar = -1;
                @exec($command, $output, $returnVar);
                if (!empty($output) || $returnVar === 0) {
                    return implode("\n", $output);
                }
            } catch (\Throwable $e) {}
        }

        // 3. Try proc_open
        if (isFunctionAvailable('proc_open')) {
            try {
                $descriptorspec = [
                    0 => ["pipe", "r"],
                    1 => ["pipe", "w"],
                    2 => ["pipe", "w"]
                ];
                $process = @proc_open($command, $descriptorspec, $pipes);
                if (is_resource($process)) {
                    fclose($pipes[0]);
                    $stdout = stream_get_contents($pipes[1]);
                    fclose($pipes[1]);
                    fclose($pipes[2]);
                    proc_close($process);
                    return $stdout !== false ? $stdout : null;
                }
            } catch (\Throwable $e) {}
        }

        // 4. Try passthru
        if (isFunctionAvailable('passthru')) {
            try {
                ob_start();
                @passthru($command);
                $res = ob_get_clean();
                if ($res !== false && $res !== '') {
                    return $res;
                }
            } catch (\Throwable $e) {}
        }

        // 5. Try popen
        if (isFunctionAvailable('popen')) {
            try {
                $handle = @popen($command, 'r');
                if ($handle) {
                    $read = '';
                    while (!feof($handle)) {
                        $read .= fread($handle, 2096);
                    }
                    pclose($handle);
                    return $read;
                }
            } catch (\Throwable $e) {}
        }

        return null;
    }
}

if (!function_exists('safeExec')) {
    /**
     * Safely executes a command and populates output array and exit code.
     */
    function safeExec(string $command, array &$output = [], int &$returnVar = 0): bool
    {
        $output = [];
        $returnVar = 1;

        if (isFunctionAvailable('exec')) {
            try {
                @exec($command, $output, $returnVar);
                return $returnVar === 0;
            } catch (\Throwable $e) {}
        }

        $res = safeShellExec($command);
        if ($res !== null) {
            $output = explode("\n", rtrim($res));
            $returnVar = 0;
            return true;
        }

        return false;
    }
}

