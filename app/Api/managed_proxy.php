<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Security\SecurityLogger;

$logger = new SecurityLogger($config['logs_dir'] . '/security');
$auth = new AuthService($config['config_dir'] . '/users.json', $logger);
$user = $auth->requireRole('admin');
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$serverId = trim((string)($_GET['server_id'] ?? ''));
$endpoint = basename(trim((string)($_GET['endpoint'] ?? '')));
$query = (string)($_GET['query'] ?? '');

$permissionByEndpoint = [
    'server_status.php' => null,
    'sites.php' => 'sites',
    'deploy.php' => 'sites',
    'rollback.php' => 'sites',
    'cancel.php' => null,
    'delete_site.php' => 'add_edit_sites',
    'save_site.php' => 'add_edit_sites',
    'custom_script.php' => 'add_edit_sites',
    'generate_script.php' => 'script_gen',
    'history.php' => 'deploy_history',
    'deployment.php' => 'deploy_history',
    'pm2.php' => 'pm2',
    'ports.php' => 'vps_ports',
    'terminal.php' => 'terminal',
    'terminal_commands.php' => null,
    'backups.php' => 'db_backups',
    'update_system.php' => 'update_system'
];

if (!array_key_exists($endpoint, $permissionByEndpoint)) {
    jsonError('PROXY_ENDPOINT_DENIED', 'This dashboard endpoint cannot be sent to a managed server.', 403);
}
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    jsonError('METHOD_NOT_ALLOWED', 'Unsupported method for managed-server requests.', 405);
}
if ($method !== 'GET' && !Csrf::validateHeaderOrPost()) {
    jsonError('CSRF_INVALID', 'Invalid CSRF token.', 403);
}

$requiredPermission = $permissionByEndpoint[$endpoint];
if ($requiredPermission !== null) {
    $auth->requirePermission($requiredPermission);
}
if ($endpoint === 'cancel.php' && !$auth->hasRole(['admin', 'deployer'])) {
    jsonError('FORBIDDEN', 'Only administrators and deployers can cancel remote deployments.', 403);
}
if ($endpoint === 'terminal_commands.php' && !$auth->hasRole('admin')) {
    jsonError('FORBIDDEN', 'Only administrators can manage remote terminal commands.', 403);
}
if ($endpoint === 'update_system.php' && !$auth->hasRole('admin')) {
    jsonError('FORBIDDEN', 'Only administrators can update a remote server.', 403);
}

$body = (string)file_get_contents('php://input');
$input = json_decode($body, true);
$action = is_array($input) ? (string)($input['action'] ?? '') : '';
if ($endpoint === 'ports.php' && $method === 'POST') {
    $auth->requirePermission('kill_port_process');
}
if ($endpoint === 'backups.php' && $method === 'POST') {
    if (in_array($action, ['get_master_creds', 'save_master_creds', 'google_oauth_start', 'delete_db', 'bulk_schedule'], true) && !$auth->hasRole('admin')) {
        jsonError('FORBIDDEN', 'Only administrators can perform this remote backup settings action.', 403);
    }
    if (in_array($action, ['run_backup', 'backup_all', 'run_master_backup', 'start_master_backup', 'delete_backup'], true) && !$auth->hasRole(['admin', 'deployer'])) {
        jsonError('FORBIDDEN', 'Insufficient permission for this remote backup action.', 403);
    }
}

$registry = safeReadJson($config['config_dir'] . '/managed_servers.json', ['servers' => []]);
$server = $registry['servers'][$serverId] ?? null;
if (!is_array($server) || empty($server['enabled']) || empty($server['url']) || empty($server['token'])) {
    jsonError('MANAGED_SERVER_NOT_FOUND', 'The selected managed server is unavailable or disabled.', 404);
}
$baseUrl = rtrim((string)$server['url'], '/');
$baseParts = parse_url($baseUrl);
if (!is_array($baseParts) || !in_array($baseParts['scheme'] ?? '', ['https'], true)) {
    jsonError('MANAGED_SERVER_URL_INVALID', 'Remote control requires an HTTPS server URL.', 400);
}
if (strlen($query) > 4096 || str_contains($query, "\r") || str_contains($query, "\n")) {
    jsonError('INVALID_QUERY', 'Remote request query is invalid.', 400);
}

$remoteUrl = $baseUrl . '/api/' . $endpoint . ($query !== '' ? '?' . $query : '');
$isDownload = $endpoint === 'backups.php' && $method === 'GET' && (($_GET['action'] ?? '') === 'download' || str_contains($query, 'action=download'));
$remoteHeaders = [];
$curl = curl_init($remoteUrl);
curl_setopt_array($curl, [
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_RETURNTRANSFER => !$isDownload,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_TIMEOUT => 180,
    CURLOPT_HTTPHEADER => [
        'X-LightDeploy-Node-Token: ' . $server['token'],
        'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? 'application/json'),
        'X-Forwarded-By: LightDeploy-Control-Plane'
    ],
    CURLOPT_HEADERFUNCTION => static function ($curlHandle, string $headerLine) use (&$remoteHeaders): int {
        $length = strlen($headerLine);
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $headerLine, $statusMatch)) {
            $remoteHeaders[':status'] = $statusMatch[1];
        }
        if (str_contains($headerLine, ':')) {
            [$name, $value] = explode(':', $headerLine, 2);
            $remoteHeaders[strtolower(trim($name))] = trim($value);
        }
        return $length;
    }
]);

if ($isDownload) {
    curl_setopt($curl, CURLOPT_WRITEFUNCTION, static function ($curlHandle, string $chunk) use (&$remoteHeaders): int {
        if (!headers_sent()) {
            http_response_code((int)($remoteHeaders[':status'] ?? 200));
            foreach (['content-type', 'content-disposition', 'content-length', 'cache-control'] as $headerName) {
                if (isset($remoteHeaders[$headerName])) {
                    header(implode('-', array_map('ucfirst', explode('-', $headerName))) . ': ' . $remoteHeaders[$headerName]);
                }
            }
        }
        echo $chunk;
        flush();
        return strlen($chunk);
    });
}

if ($method !== 'GET') {
    $headers = [
        'X-LightDeploy-Node-Token: ' . $server['token'],
        'Accept: ' . ($_SERVER['HTTP_ACCEPT'] ?? 'application/json'),
        'X-Forwarded-By: LightDeploy-Control-Plane'
    ];
    if (!empty($_SERVER['CONTENT_TYPE'])) {
        $headers[] = 'Content-Type: ' . $_SERVER['CONTENT_TYPE'];
    }
    curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
}

$responseBody = curl_exec($curl);
$status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
$error = curl_error($curl);
curl_close($curl);

$logger->log('MANAGED_SERVER_PROXY', [
    'server_id' => $serverId,
    'endpoint' => $endpoint,
    'method' => $method,
    'status' => $status ?: null
], $user['username'] ?? null);

if ($responseBody === false || $status === 0) {
    if ($isDownload && headers_sent()) {
        exit;
    }
    jsonError('MANAGED_SERVER_UNREACHABLE', 'Could not reach selected server: ' . ($error ?: 'connection failed'), 502);
}

if ($isDownload) {
    exit;
}

http_response_code($status);
foreach (['content-type', 'content-disposition', 'content-length', 'cache-control', 'last-modified'] as $headerName) {
    if (isset($remoteHeaders[$headerName])) {
        header(implode('-', array_map('ucfirst', explode('-', $headerName))) . ': ' . $remoteHeaders[$headerName]);
    }
}
if (!headers_sent() && !isset($remoteHeaders['content-type'])) {
    header('Content-Type: application/json; charset=utf-8');
}
echo $responseBody;
