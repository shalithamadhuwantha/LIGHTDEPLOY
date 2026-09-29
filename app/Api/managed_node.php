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
$totalDisk = @disk_total_space('/');
$freeDisk = @disk_free_space('/');

jsonSuccess([
    'node' => [
        'hostname' => gethostname() ?: 'unknown',
        'php_version' => PHP_VERSION,
        'checked_at' => date(DATE_ATOM),
        'uptime_seconds' => $uptimeSeconds,
        'load_average' => array_values($load ?: [0.0, 0.0, 0.0]),
        'disk_total_bytes' => $totalDisk === false ? null : $totalDisk,
        'disk_free_bytes' => $freeDisk === false ? null : $freeDisk
    ]
]);
