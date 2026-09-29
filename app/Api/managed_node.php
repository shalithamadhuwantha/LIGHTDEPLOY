<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentLog;

$tokenData = safeReadJson($config['config_dir'] . '/node_control.json', []);
$storedHash = (string)($tokenData['token_hash'] ?? '');
$authorization = (string)($_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
if ($authorization !== '' && !str_starts_with($authorization, 'Bearer ')) {
    $authorization = 'Bearer ' . $authorization;
}
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

$siteConfig = safeReadJson($config['config_dir'] . '/sites.json', ['sites' => []]);
$deploymentHistory = (new DeploymentLog($config['logs_dir']))->getHistory(100);
$deploymentBySite = [];
foreach ($deploymentHistory as $deployment) {
    $siteId = (string)($deployment['site_id'] ?? '');
    if ($siteId !== '' && !isset($deploymentBySite[$siteId])) {
        $deploymentBySite[$siteId] = [
            'status' => $deployment['status'] ?? 'unknown',
            'start_time' => $deployment['start_time'] ?? null
        ];
    }
}
$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$safeSites = [];
foreach (($siteConfig['sites'] ?? []) as $siteId => $site) {
    $safeSites[$siteId] = [
        'id' => (string)$siteId,
        'name' => (string)($site['name'] ?? $siteId),
        'domain' => (string)($site['domain'] ?? ''),
        'enabled' => !empty($site['enabled']),
        'is_locked' => $lockManager->isLocked((string)$siteId),
        'health_check_enabled' => !empty($site['health_check_enabled']),
        'pm2_enabled' => !empty($site['pm2_enabled']),
        'has_rollback' => !empty($site['rollback_script']),
        'last_deployment' => $deploymentBySite[$siteId] ?? null
    ];
}

jsonSuccess([
    'node' => [
        'hostname' => gethostname() ?: 'unknown',
        'php_version' => PHP_VERSION,
        'checked_at' => date(DATE_ATOM),
        'uptime_seconds' => $uptimeSeconds,
        'site_count' => count($safeSites),
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
    ],
    'sites' => $safeSites
]);
