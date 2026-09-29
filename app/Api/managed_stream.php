<?php
declare(strict_types=1);

@ini_set('zlib.output_compression', '0');
@ini_set('implicit_flush', '1');
set_time_limit(0);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;

$logger = new SecurityLogger($config['logs_dir'] . '/security');
$auth = new AuthService($config['config_dir'] . '/users.json', $logger);
$user = $auth->requireRole('admin');
$serverId = trim((string)($_GET['server_id'] ?? ''));
$deploymentId = trim((string)($_GET['deployment_id'] ?? ''));
if (!(new InputValidator($config['scripts_dir']))->validateDeploymentId($deploymentId)) {
    jsonError('INVALID_DEPLOYMENT_ID', 'Invalid deployment ID.', 400);
}

$registry = safeReadJson($config['config_dir'] . '/managed_servers.json', ['servers' => []]);
$server = $registry['servers'][$serverId] ?? null;
if (!is_array($server) || empty($server['enabled']) || empty($server['url']) || empty($server['token'])) {
    jsonError('MANAGED_SERVER_NOT_FOUND', 'The selected managed server is unavailable or disabled.', 404);
}
$parts = parse_url((string)$server['url']);
if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https') {
    jsonError('MANAGED_SERVER_URL_INVALID', 'Remote log streaming requires HTTPS.', 400);
}

session_write_close();
$remoteUrl = rtrim((string)$server['url'], '/') . '/api/stream.php?deployment_id=' . rawurlencode($deploymentId);
$lastEventId = (string)($_SERVER['HTTP_LAST_EVENT_ID'] ?? '');
$streamStatus = 0;
$errorBody = '';
$streamStarted = false;
$curl = curl_init($remoteUrl);
$headers = ['Authorization: Bearer ' . $server['token'], 'Accept: text/event-stream'];
if ($lastEventId !== '' && ctype_digit($lastEventId)) {
    $headers[] = 'Last-Event-ID: ' . $lastEventId;
}
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 0,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$streamStatus, &$streamStarted): int {
        $length = strlen($headerLine);
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $match)) {
            $streamStatus = (int)$match[1];
        }
        if (stripos($headerLine, 'Content-Type: text/event-stream') === 0 && $streamStatus === 200) {
            if (!headers_sent()) {
                http_response_code(200);
                header('Content-Type: text/event-stream');
                header('Cache-Control: no-cache, no-store, must-revalidate');
                header('X-Accel-Buffering: no');
                header('Connection: keep-alive');
            }
            while (ob_get_level() > 0) ob_end_flush();
            flush();
            $streamStarted = true;
        }
        return $length;
    },
    CURLOPT_WRITEFUNCTION => static function ($curlHandle, string $chunk) use (&$streamStatus, &$errorBody, &$streamStarted): int {
        if ($streamStarted && $streamStatus === 200) {
            echo $chunk;
            flush();
        } else {
            $errorBody .= $chunk;
        }
        return strlen($chunk);
    }
]);
$result = curl_exec($curl);
$error = curl_error($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

$logger->log('MANAGED_SERVER_STREAM', ['server_id' => $serverId, 'deployment_id' => $deploymentId, 'status' => $status], $user['username'] ?? null);
if (!$streamStarted) {
    if (!headers_sent()) {
        http_response_code($status > 0 ? $status : 502);
        header('Content-Type: application/json; charset=utf-8');
    }
    $remoteError = json_decode($errorBody, true);
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'REMOTE_STREAM_FAILED',
            'message' => $remoteError['error']['message'] ?? $error ?: 'Remote log stream failed.'
        ]
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
exit;
