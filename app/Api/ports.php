<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API - Open VPS Ports Discovery & Assignment Manager
 * Scans active listening sockets, extracts process names/PIDs, and checks site/port availability.
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$authService->requirePermission('vps_ports');

$openPorts = [];
$usedPortNumbers = [];

// 1. Stage 1: Try system socket commands (ss, netstat, lsof)
$output = safeShellExec('ss -tulpn 2>/dev/null || netstat -tulpn 2>/dev/null || lsof -i -P -n 2>/dev/null') ?? '';

if (!empty($output)) {
    $lines = explode("\n", $output);
    foreach ($lines as $line) {
        $line = trim($line);
        if (empty($line) || str_starts_with($line, 'Netid') || str_starts_with($line, 'Active') || str_starts_with($line, 'COMMAND')) {
            continue;
        }

        $proto = str_contains(strtolower($line), 'udp') ? 'UDP' : 'TCP';

        // Extract process & PID if present
        $procName = 'system';
        $pid = 0;
        if (preg_match('/users:\(\("([^"]+)",pid=(\d+)/i', $line, $pm)) {
            $procName = $pm[1];
            $pid = (int)$pm[2];
        } elseif (preg_match('/([a-zA-Z0-9_\-\.]+)\s+(\d+)\s+.*?\s+(?:TCP|UDP)/i', $line, $pm)) {
            $procName = $pm[1];
            $pid = (int)$pm[2];
        }

        // Extract address & port
        $bindAddr = '0.0.0.0';
        $port = 0;

        if (preg_match('/(?:LISTEN|UNCONN)?\s+\d+\s+\d+\s+([0-9a-fA-F\.\:\*\[\]]+)[:\.](\d+)\s+/i', $line, $am)) {
            $bindAddr = trim($am[1], '[]');
            $port = (int)$am[2];
        } elseif (preg_match('/([0-9a-fA-F\.\:\*\[\]]+)[:\.](\d+)\s+[0-9a-fA-F\.\:\*\[\]]+/i', $line, $am)) {
            $bindAddr = trim($am[1], '[]');
            $port = (int)$am[2];
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
}

// 2. Stage 2: Fallback to /proc/net/tcp and /proc/net/tcp6 if sockets list is empty
if (empty($openPorts)) {
    $parseProcNet = function(string $filePath, string $protoName) use (&$openPorts, &$usedPortNumbers) {
        if (!is_readable($filePath)) return;
        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!$lines || count($lines) <= 1) return;

        array_shift($lines); // remove header
        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line));
            if (count($parts) < 4) continue;

            $state = $parts[3] ?? '';
            // 0A represents LISTEN state in /proc/net/tcp
            if ($state !== '0A') continue;

            $localAddr = explode(':', $parts[1] ?? '');
            if (count($localAddr) < 2) continue;

            $port = (int)hexdec($localAddr[1]);
            if ($port > 0 && $port < 65536) {
                $key = $port . '_' . $protoName;
                if (!isset($usedPortNumbers[$key])) {
                    $usedPortNumbers[$key] = true;
                    $ipHex = $localAddr[0];
                    $ipStr = '0.0.0.0';
                    if (strlen($ipHex) === 8) {
                        $ipStr = long2ip(hexdec(implode('', array_reverse(str_split($ipHex, 2)))));
                    }

                    $openPorts[] = [
                        'port' => $port,
                        'proto' => $protoName,
                        'bind' => $ipStr,
                        'process' => 'system',
                        'pid' => 0,
                        'is_public' => ($ipStr === '0.0.0.0' || $ipStr === '::')
                    ];
                }
            }
        }
    };

    $parseProcNet('/proc/net/tcp', 'TCP');
    $parseProcNet('/proc/net/tcp6', 'TCP');
}

// 3. Stage 3: Auto-label known service ports if process is generic/system
$knownServices = [
    80 => 'Nginx / Web Server',
    443 => 'Nginx / SSL Web Server',
    3306 => 'MySQL / MariaDB',
    6379 => 'Redis Server',
    27017 => 'MongoDB',
    5432 => 'PostgreSQL',
    9000 => 'PHP-FPM',
    9001 => 'PHP-FPM',
    22 => 'SSH Daemon',
    21 => 'FTP Daemon',
    25 => 'Postfix / SMTP',
    53 => 'DNS Server'
];

foreach ($openPorts as &$p) {
    if (($p['process'] === 'system' || empty($p['process'])) && isset($knownServices[$p['port']])) {
        $p['process'] = $knownServices[$p['port']];
    }
}
unset($p);

// Sort open ports numerically
usort($openPorts, fn($a, $b) => $a['port'] <=> $b['port']);

// 4. Cross-reference configured sites in sites.json to map LightDeploy App Names
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

// 5. Compute suggested available/free ports for new application assignment
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
