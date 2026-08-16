<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: SSE Live Log Stream
 * GET /api/stream.php?deployment_id=...
 */

// Disable buffer limits & set execution time unlimited
@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
set_time_limit(0);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Deployment\DeploymentLock;
use LightDeploy\Deployment\DeploymentRunner;
use LightDeploy\Deployment\DeploymentLog;
use LightDeploy\Deployment\HealthChecker;
use LightDeploy\Deployment\DeploymentService;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$user = $authService->requireAuth();
session_write_close(); // Release session file lock so concurrent requests (e.g. /api/sites.php) are not blocked

$deploymentId = trim((string)($_GET['deployment_id'] ?? ''));

$validator = new InputValidator($config['scripts_dir']);
if (!$validator->validateDeploymentId($deploymentId)) {
    jsonError('INVALID_DEPLOYMENT_ID', 'Invalid deployment ID format.', 400);
}

$lockManager = new DeploymentLock($config['runtime_dir'] . '/locks');
$runner = new DeploymentRunner($config['runtime_dir']);
$logger = new DeploymentLog($config['logs_dir']);
$healthChecker = new HealthChecker();
$deploymentService = new DeploymentService($validator, $lockManager, $runner, $logger, $healthChecker, $securityLogger, $config);

$streamFile = $runner->getStreamPath($deploymentId);

// Prepare SSE HTTP Headers
if (!headers_sent()) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('X-Accel-Buffering: no'); // Crucial for Nginx SSE
    header('Connection: keep-alive');
}

// Clear any existing output buffers
while (ob_get_level() > 0) {
    ob_end_flush();
}
flush();

$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

$filePointer = 0;

// Reconnect byte offset if client specified Last-Event-ID or offset header
if (isset($_SERVER['HTTP_LAST_EVENT_ID']) && is_numeric($_SERVER['HTTP_LAST_EVENT_ID'])) {
    $filePointer = (int)$_SERVER['HTTP_LAST_EVENT_ID'];
}

$maxIdleLoops = 1800; // 30 minutes safety cutoff
$loopCount = 0;

while ($loopCount < $maxIdleLoops) {
    $loopCount++;

    // Check if client disconnected
    if (connection_aborted()) {
        break;
    }

    // Read log stream updates
    if (file_exists($streamFile)) {
        $fp = fopen($streamFile, 'r');
        if ($fp) {
            fseek($fp, $filePointer);
            while (($line = fgets($fp)) !== false) {
                $lineStr = rtrim($line, "\r\n");
                echo "event: log\n";
                echo "data: " . json_encode(['line' => $lineStr], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
                echo "id: " . ftell($fp) . "\n\n";
                flush();
            }
            $filePointer = ftell($fp);
            fclose($fp);
        }
    }

    // Load site configuration for health check reference
    $jobPath = $runner->getJobPath($deploymentId);
    $meta = file_exists($jobPath) ? safeReadJson($jobPath, []) : [];
    $siteId = $meta['site_id'] ?? '';
    $siteConfig = $configuredSites[$siteId] ?? null;

    // Update state
    $currentState = $deploymentService->updateDeploymentState($deploymentId, $siteConfig);
    $status = $currentState['status'] ?? 'unknown';

    // Broadcast status event
    echo "event: status\n";
    echo "data: " . json_encode($currentState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n\n";
    flush();

    // Terminal statuses end the stream
    if (in_array($status, ['success', 'failed', 'cancelled', 'timeout', 'health_check_failed'], true)) {
        // Send final end event
        echo "event: end\n";
        echo "data: " . json_encode(['status' => $status, 'deployment_id' => $deploymentId]) . "\n\n";
        flush();
        break;
    }

    usleep(300000); // 300ms delay between polls
}

exit;
