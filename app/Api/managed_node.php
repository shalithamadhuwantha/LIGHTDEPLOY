<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

$tokenData = safeReadJson($config['config_dir'] . '/node_control.json', []);
$storedHash = (string)($tokenData['token_hash'] ?? '');
$authorization = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authorization === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strtolower((string)$headerName) === 'authorization') {
            $authorization = (string)$headerValue;
            break;
        }
    }
}

if ($storedHash === '' || !preg_match('/^Bearer\s+([A-Za-z0-9_-]{48,})$/', $authorization, $matches) || !hash_equals($storedHash, hash('sha256', $matches[1]))) {
    jsonError('UNAUTHORIZED', 'A valid managed-server token is required.', 401);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    jsonError('METHOD_NOT_ALLOWED', 'Managed-node status supports GET only.', 405);
}

$uptimeSeconds = 0;
if (is_readable('/proc/uptime')) {
    $uptimeText = (string)file_get_contents('/proc/uptime');
    $uptimeSeconds = (int)explode(' ', trim($uptimeText))[0];
}
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];
$cpuCount = 1;
if (is_readable('/proc/cpuinfo')) {
    $cpuInfo = (string)file_get_contents('/proc/cpuinfo');
    $cpuCount = max(1, preg_match_all('/^processor\s*:/m', $cpuInfo));
}
$cpuPercent = min(100, max(0, (($load[0] ?? 0) / $cpuCount) * 100));
$memory = [];
if (is_readable('/proc/meminfo')) {
    foreach (file('/proc/meminfo') ?: [] as $line) {
        if (preg_match('/^(MemTotal|MemAvailable|MemFree|Buffers|Cached):\s+(\d+)/', $line, $matches)) {
            $memory[$matches[1]] = (int)$matches[2];
        }
    }
}
$memoryTotal = $memory['MemTotal'] ?? 1;
$memoryAvailable = $memory['MemAvailable'] ?? (($memory['MemFree'] ?? 0) + ($memory['Buffers'] ?? 0) + ($memory['Cached'] ?? 0));
$memoryUsed = max(0, $memoryTotal - $memoryAvailable);
$memoryPercent = round(($memoryUsed / max(1, $memoryTotal)) * 100, 1);
$totalDisk = @disk_total_space('/');
$freeDisk = @disk_free_space('/');
$usedDisk = ($totalDisk !== false && $freeDisk !== false) ? max(0, $totalDisk - $freeDisk) : 0;
$diskPercent = ($totalDisk !== false && $totalDisk > 0) ? round(($usedDisk / $totalDisk) * 100, 1) : 0;
$overallLoad = round(($cpuPercent * 0.45) + ($memoryPercent * 0.45) + ($diskPercent * 0.10), 1);
$uptimeFormatted = sprintf('%dd %dh %dm', floor($uptimeSeconds / 86400), floor(($uptimeSeconds % 86400) / 3600), floor(($uptimeSeconds % 3600) / 60));
$loadList = array_values($load ?: [0.0, 0.0, 0.0]);
$appMemory = memory_get_usage(true);

jsonSuccess([
    'node' => [
        'hostname' => gethostname() ?: 'unknown',
        'php_version' => PHP_VERSION,
        'checked_at' => date(DATE_ATOM),
        'uptime_seconds' => $uptimeSeconds,
        'load_average' => $loadList,
        'disk_total_bytes' => $totalDisk === false ? null : $totalDisk,
        'disk_free_bytes' => $freeDisk === false ? null : $freeDisk
    ],
    'metrics' => [
        'overall_load' => $overallLoad,
        'cpu' => [
            'load_1m' => round($cpuPercent, 1),
            'load_5m' => round(min(100, max(0, (($load[1] ?? 0) / $cpuCount) * 100)), 1),
            'load_15m' => round(min(100, max(0, (($load[2] ?? 0) / $cpuCount) * 100)), 1)
        ],
        'memory' => [
            'total_mb' => round($memoryTotal / 1024),
            'used_mb' => round($memoryUsed / 1024),
            'free_mb' => round($memoryAvailable / 1024),
            'percentage' => $memoryPercent
        ],
        'disk' => [
            'total_gb' => $totalDisk === false ? 0 : round($totalDisk / (1024 ** 3), 1),
            'used_gb' => round($usedDisk / (1024 ** 3), 1),
            'free_gb' => $freeDisk === false ? 0 : round($freeDisk / (1024 ** 3), 1),
            'percentage' => $diskPercent
        ],
        'uptime' => $uptimeFormatted,
        'hostname' => gethostname() ?: 'unknown',
        'app_resources' => [
            'rss_mb' => round($appMemory / (1024 * 1024), 2),
            'php_version' => PHP_VERSION
        ]
    ]
]);
