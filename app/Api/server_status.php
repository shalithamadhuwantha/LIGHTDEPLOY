<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Server Performance Status
 * GET /api/server_status
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$authService->requireAuth();

// RAM metrics from /proc/meminfo
$memInfo = [];
if (file_exists('/proc/meminfo')) {
    $lines = file('/proc/meminfo');
    foreach ($lines as $line) {
        if (preg_match('/^(\w+):\s+(\d+)/', $line, $matches)) {
            $memInfo[$matches[1]] = (int)$matches[2]; // kB
        }
    }
}

$memTotal = $memInfo['MemTotal'] ?? 1;
$memFree = $memInfo['MemFree'] ?? 0;
$memAvailable = $memInfo['MemAvailable'] ?? ($memFree + ($memInfo['Buffers'] ?? 0) + ($memInfo['Cached'] ?? 0));
$memUsed = $memTotal - $memAvailable;
$memPercentage = round(($memUsed / $memTotal) * 100, 1);

// Disk metrics
$totalDisk = @disk_total_space('/');
$freeDisk = @disk_free_space('/');
$usedDisk = ($totalDisk && $freeDisk) ? ($totalDisk - $freeDisk) : 0;
$diskPercentage = ($totalDisk > 0) ? round(($usedDisk / $totalDisk) * 100, 1) : 0;

// CPU Load averages
$load = function_exists('sys_getloadavg') ? sys_getloadavg() : [0.0, 0.0, 0.0];

// Server Uptime
$uptimeSeconds = 0;
if (file_exists('/proc/uptime')) {
    $str = file_get_contents('/proc/uptime');
    $num = explode(' ', trim($str))[0] ?? 0;
    $uptimeSeconds = (int)$num;
}

$days = floor($uptimeSeconds / 86400);
$hours = floor(($uptimeSeconds % 86400) / 3600);
$mins = floor(($uptimeSeconds % 3600) / 60);
$uptimeFormatted = "{$days}d {$hours}h {$mins}m";

jsonSuccess([
    'cpu' => [
        'load_1m' => $load[0] ?? 0,
        'load_5m' => $load[1] ?? 0,
        'load_15m' => $load[2] ?? 0
    ],
    'memory' => [
        'total_mb' => round($memTotal / 1024),
        'used_mb' => round($memUsed / 1024),
        'free_mb' => round($memAvailable / 1024),
        'percentage' => $memPercentage
    ],
    'disk' => [
        'total_gb' => round($totalDisk / (1024 * 1024 * 1024), 1),
        'used_gb' => round($usedDisk / (1024 * 1024 * 1024), 1),
        'free_gb' => round($freeDisk / (1024 * 1024 * 1024), 1),
        'percentage' => $diskPercentage
    ],
    'uptime' => $uptimeFormatted,
    'hostname' => gethostname()
]);
