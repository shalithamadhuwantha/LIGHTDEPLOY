<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Self Health Status Check
 * GET /api/health
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

$dirsToCheck = [
    'config' => $config['config_dir'],
    'logs' => $config['logs_dir'],
    'runtime' => $config['runtime_dir'],
    'scripts' => $config['scripts_dir'],
];

$writable = [];
$allWritable = true;
foreach ($dirsToCheck as $name => $path) {
    $isW = is_writable($path);
    $writable[$name] = $isW;
    if (!$isW && $name !== 'scripts') {
        $allWritable = false;
    }
}

jsonSuccess([
    'status' => $allWritable ? 'UP' : 'WARNING',
    'php_version' => PHP_VERSION,
    'time' => date('Y-m-d H:i:s'),
    'filesystem' => $writable
]);
