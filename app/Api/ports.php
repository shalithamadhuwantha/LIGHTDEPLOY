<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API - Open VPS Ports Discovery & Assignment Manager
 * Scans active listening sockets, extracts process names/PIDs, and checks site/port availability.
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;

$authService = new AuthService($config['config_dir'] . '/users.json');
if (!$authService->isAuthenticated()) {
    jsonError('UNAUTHORIZED', 'Authentication required.', 401);
}

// 1. Fetch listening ports from Linux system using ss, netstat, or lsof
$output = shell_exec('ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null || lsof -i -P -n 2>/dev/null') ?? '';

$openPorts = [];
$usedPortNumbers = [];

$lines = explode("\n", $output);
foreach ($lines as $line) {
    $line = trim($line);
    if (empty($line) || str_starts_with($line, 'Netid') || str_starts_with($line, 'Active')) {
        continue;
    }

    $proto = 'TCP';
    if (str_starts_with(strtolower($line), 'udp') || str_contains(strtolower($line), 'udp')) {
        $proto = 'UDP';
    }

    // Match local address and port: e.g. 127.0.0.1:8085, 0.0.0.0:3000, [::]:80, *:8085
    $bindAddr = '0.0.0.0';
    $port = 0;
    $procName = 'system';
    $pid = 0;

    if (preg_match('/(?:LISTEN|UNCONN)?\s+\d+\s+\d+\s+([^\s]+):(\d+)\s+.*?(?:users:\(\("([^"]+)",pid=(\d+)/i', $line, $matches)) {
        $bindAddr = $matches[1];
        $port = (int)$matches[2];
        $procName = $matches[3] ?? 'system';
        $pid = (int)($matches[4] ?? 0);
    } elseif (preg_match('/([0-9a-fA-F\.\:\*]+):(\d+)\s+.*?(?:users:\(\("([^"]+)",pid=(\d+)/i', $line, $matches)) {
        $bindAddr = $matches[1];
        $port = (int)$matches[2];
        $procName = $matches[3] ?? 'system';
        $pid = (int)($matches[4] ?? 0);
    } elseif (preg_match('/([0-9a-fA-F\.\:\*]+):(\d+)\s+/i', $line, $matches)) {
        $bindAddr = $matches[1];
        $port = (int)$matches[2];
        if (preg_match('/users:\(\("([^"]+)",pid=(\d+)/i', $line, $pMatches)) {
            $procName = $pMatches[1];
            $pid = (int)$pMatches[2];
        }
    }

    if ($port > 0 && $port < 65536) {
        $key = $port . '_' . $proto;
        if (!isset($usedPortNumbers[$key])) {
            $usedPortNumbers[$key] = true;
            $isPublic = ($bindAddr === '0.0.0.0' || $bindAddr === '*' || $bindAddr === '::' || $bindAddr === ':::');

            $openPorts[] = [
                'port' => $port,
                'proto' => $proto,
                'bind' => $bindAddr,
                'process' => $procName,
                'pid' => $pid,
                'is_public' => $isPublic
            ];
        }
    }
}

// Sort open ports numerically
usort($openPorts, fn($a, $b) => $a['port'] <=> $b['port']);

// 2. Cross-reference configured sites in sites.json to map LightDeploy App Names
$sitesFile = $config['config_dir'] . '/sites.json';
$sitesData = safeReadJson($sitesFile, ['sites' => []]);
$configuredSites = $sitesData['sites'] ?? [];

foreach ($openPorts as &$pItem) {
    $pItem['matched_site'] = null;
    $pItem['site_name'] = null;

    foreach ($configuredSites as $sId => $sConfig) {
        $sDomain = $sConfig['domain'] ?? '';
        $sName = $sConfig['name'] ?? $sId;
        $sHealth = $sConfig['health_check'] ?? '';

        if ((!empty($sDomain) && str_contains($sDomain, ':' . $pItem['port'])) || 
            (!empty($sHealth) && str_contains($sHealth, ':' . $pItem['port']))) {
            $pItem['matched_site'] = $sId;
            $pItem['site_name'] = $sName;
            break;
        }
    }
}
unset($pItem);

// 3. Compute suggested available/free ports for new application assignment
$candidatePorts = [3000, 3001, 3002, 3003, 4000, 5000, 8000, 8080, 8081, 8085, 8888, 9000, 9090];
$availableSuggestedPorts = [];

foreach ($candidatePorts as $candPort) {
    if (!isset($usedPortNumbers[$candPort . '_TCP']) && !isset($usedPortNumbers[$candPort . '_UDP'])) {
        $availableSuggestedPorts[] = $candPort;
    }
}

jsonSuccess([
    'total_open_ports' => count($openPorts),
    'ports' => $openPorts,
    'suggested_free_ports' => $availableSuggestedPorts,
    'timestamp' => date('Y-m-d H:i:s')
]);
