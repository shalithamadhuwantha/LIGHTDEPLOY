<?php
declare(strict_types=1);

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$auth = new AuthService($config['config_dir'] . '/users.json');
$auth->requireRole('admin');
$serversFile = $config['config_dir'] . '/managed_servers.json';
$nodeTokenFile = $config['config_dir'] . '/node_control.json';

$readRegistry = static function () use ($serversFile): array {
    return safeReadJson($serversFile, ['servers' => []]);
};

$writeRegistry = static function (array $servers) use ($serversFile): void {
    if (!safeWriteJson($serversFile, ['servers' => $servers])) {
        throw new RuntimeException('Could not save managed server settings.');
    }
    @chmod($serversFile, 0600);
};

$probeServer = static function (string $url, string $token): array {
    $curl = curl_init(rtrim($url, '/') . '/api/managed_node.php');
    curl_setopt_array($curl, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token, 'Accept: application/json']
    ]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    $error = curl_error($curl);
    curl_close($curl);

    $response = is_string($body) ? json_decode($body, true) : null;
    if ($status !== 200 || empty($response['success']) || empty($response['node']['hostname'])) {
        $message = $response['error']['message'] ?? $error ?: 'HTTP ' . $status;
        throw new RuntimeException('Connection failed: ' . $message);
    }
    return $response;
};

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $servers = $readRegistry()['servers'] ?? [];
    if (($_GET['view'] ?? '') === 'overview') {
        $id = trim((string)($_GET['id'] ?? ''));
        if ($id === '' || !isset($servers[$id]) || empty($servers[$id]['enabled'])) {
            jsonError('NOT_FOUND', 'Enabled managed server was not found.', 404);
        }
        try {
            $node = $probeServer((string)$servers[$id]['url'], (string)$servers[$id]['token']);
            $servers[$id]['last_status'] = 'online';
            $servers[$id]['last_checked_at'] = date('Y-m-d H:i:s');
            $servers[$id]['last_hostname'] = $node['node']['hostname'];
            $writeRegistry($servers);
            jsonSuccess([
                'server' => ['id' => $id, 'name' => $servers[$id]['name'], 'url' => $servers[$id]['url']],
                'node' => $node['node'],
                'metrics' => $node['metrics'],
                'sites' => $node['sites'] ?? []
            ]);
        } catch (Throwable $error) {
            $servers[$id]['last_status'] = 'offline';
            $servers[$id]['last_checked_at'] = date('Y-m-d H:i:s');
            $writeRegistry($servers);
            jsonError('SERVER_UNREACHABLE', $error->getMessage(), 502);
        }
    }

    foreach ($servers as &$server) {
        $server['token_configured'] = !empty($server['token']);
        unset($server['token']);
    }
    unset($server);
    jsonSuccess([
        'servers' => array_values($servers),
        'node_token_configured' => is_file($nodeTokenFile) && !empty(safeReadJson($nodeTokenFile, [])['token_hash'])
    ]);
}

if ($method !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Unsupported request method.', 405);
}
if (!Csrf::validateHeaderOrPost()) {
    jsonError('CSRF_INVALID', 'Invalid CSRF token.', 403);
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$action = (string)($input['action'] ?? '');

try {
    switch ($action) {
        case 'generate_node_token':
            $token = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            if (!safeWriteJson($nodeTokenFile, ['token_hash' => hash('sha256', $token), 'created_at' => date('Y-m-d H:i:s')])) {
                throw new RuntimeException('Could not save this server token.');
            }
            @chmod($nodeTokenFile, 0600);
            jsonSuccess(['message' => 'Node token generated. Copy it now; it will not be shown again.', 'token' => $token]);
            break;

        case 'save_server':
            $id = trim((string)($input['id'] ?? ''));
            $name = trim((string)($input['name'] ?? ''));
            $url = rtrim(trim((string)($input['url'] ?? '')), '/');
            $token = trim((string)($input['token'] ?? ''));
            $parts = parse_url($url);
            $isLocalHttp = is_array($parts)
                && ($parts['scheme'] ?? '') === 'http'
                && in_array($parts['host'] ?? '', ['localhost', '127.0.0.1', '::1'], true);
            if ($name === '' || !is_array($parts) || empty($parts['host']) || (!in_array($parts['scheme'] ?? '', ['https'], true) && !$isLocalHttp) || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
                throw new InvalidArgumentException('Enter a server name and a valid HTTPS base URL. HTTP is allowed only for localhost testing.');
            }

            $registry = $readRegistry();
            $servers = $registry['servers'] ?? [];
            if ($id !== '' && isset($servers[$id]) && $token === '') {
                $token = (string)($servers[$id]['token'] ?? '');
            }
            if ($token === '') {
                throw new InvalidArgumentException('Paste the node token generated on the remote server.');
            }
            if ($id === '') {
                $id = 'srv_' . bin2hex(random_bytes(6));
            }
            $servers[$id] = [
                'id' => $id,
                'name' => $name,
                'url' => $url,
                'token' => $token,
                'enabled' => !empty($input['enabled']),
                'updated_at' => date('Y-m-d H:i:s'),
                'last_status' => $servers[$id]['last_status'] ?? null,
                'last_checked_at' => $servers[$id]['last_checked_at'] ?? null,
                'last_hostname' => $servers[$id]['last_hostname'] ?? null
            ];
            $writeRegistry($servers);
            $safe = $servers[$id];
            unset($safe['token']);
            $safe['token_configured'] = true;
            jsonSuccess(['message' => 'Managed server settings saved.', 'server' => $safe]);
            break;

        case 'delete_server':
            $id = trim((string)($input['id'] ?? ''));
            $registry = $readRegistry();
            $servers = $registry['servers'] ?? [];
            if ($id === '' || !isset($servers[$id])) {
                jsonError('NOT_FOUND', 'Managed server was not found.', 404);
            }
            unset($servers[$id]);
            $writeRegistry($servers);
            jsonSuccess(['message' => 'Managed server removed from this dashboard. No data on that server was changed.']);
            break;

        case 'test_server':
            $id = trim((string)($input['id'] ?? ''));
            $registry = $readRegistry();
            $servers = $registry['servers'] ?? [];
            if ($id === '' || !isset($servers[$id])) {
                jsonError('NOT_FOUND', 'Managed server was not found.', 404);
            }
            try {
                $node = $probeServer((string)$servers[$id]['url'], (string)$servers[$id]['token']);
                $servers[$id]['last_status'] = 'online';
                $servers[$id]['last_checked_at'] = date('Y-m-d H:i:s');
                $servers[$id]['last_hostname'] = $node['node']['hostname'];
                $writeRegistry($servers);
                jsonSuccess(['message' => 'Connection successful.', 'node' => $node['node']]);
            } catch (Throwable $error) {
                $servers[$id]['last_status'] = 'offline';
                $servers[$id]['last_checked_at'] = date('Y-m-d H:i:s');
                $writeRegistry($servers);
                jsonError('SERVER_UNREACHABLE', $error->getMessage(), 502);
            }
            break;

        default:
            jsonError('INVALID_ACTION', 'Unsupported managed-server action.', 400);
    }
} catch (InvalidArgumentException $error) {
    jsonError('INVALID_INPUT', $error->getMessage(), 400);
} catch (Throwable $error) {
    jsonError('MANAGED_SERVER_ERROR', $error->getMessage(), 500);
}
