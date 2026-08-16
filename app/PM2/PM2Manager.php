<?php
declare(strict_types=1);

namespace LightDeploy\PM2;

/**
 * LIGHTDEPLOY PM2 Process Manager Wrapper
 */
class PM2Manager
{
    private string $pm2Path;

    public function __construct(?string $customPath = null)
    {
        if ($customPath && file_exists($customPath)) {
            $this->pm2Path = $customPath;
            return;
        }

        // 1. Try `which pm2`
        $path = trim((string)\safeShellExec('which pm2 2>/dev/null'));
        if (!empty($path) && file_exists($path)) {
            $this->pm2Path = $path;
            return;
        }

        // 2. Comprehensive binary discovery across system & NVM environments
        $nvmPaths = array_merge(
            glob('/root/.nvm/versions/node/*/bin/pm2') ?: [],
            glob((getenv('HOME') ?: '/root') . '/.nvm/versions/node/*/bin/pm2') ?: [],
            glob('/home/*/.nvm/versions/node/*/bin/pm2') ?: []
        );

        $candidates = array_merge([
            '/usr/local/bin/pm2',
            '/usr/bin/pm2',
            '/opt/node/bin/pm2',
            getenv('HOME') . '/.npm-global/bin/pm2',
            '/root/.npm-global/bin/pm2'
        ], $nvmPaths);

        foreach ($candidates as $candidate) {
            if (!empty($candidate) && file_exists($candidate) && is_executable($candidate)) {
                $this->pm2Path = $candidate;
                return;
            }
        }

        $this->pm2Path = 'pm2';
    }

    /**
     * Check if PM2 CLI is installed and available
     */
    public function isInstalled(): bool
    {
        $cmd = escapeshellcmd($this->pm2Path) . ' -v 2>&1';
        $output = \safeShellExec($cmd);
        if (empty($output)) {
            return false;
        }
        $outputStr = trim((string)$output);
        return (bool)preg_match('/\d+\.\d+\.\d+/', $outputStr);
    }

    /**
     * Get installed PM2 version
     */
    public function getVersion(): ?string
    {
        $output = \safeShellExec(escapeshellcmd($this->pm2Path) . ' -v 2>&1');
        return $output ? trim((string)$output) : null;
    }

    /**
     * List all PM2 managed processes
     */
    public function listProcesses(): array
    {
        if (!$this->isInstalled()) {
            return [];
        }

        $cmd = escapeshellcmd($this->pm2Path) . ' jlist 2>&1';
        $json = \safeShellExec($cmd);
        if (!$json) {
            return [];
        }

        $rawList = json_decode($json, true);
        if (!is_array($rawList)) {
            return [];
        }

        $processes = [];
        foreach ($rawList as $proc) {
            $pm2Env = $proc['pm2_env'] ?? [];
            $monit = $proc['monit'] ?? [];

            $argsStr = '';
            if (isset($pm2Env['args'])) {
                $argsStr = is_array($pm2Env['args']) ? implode(' ', $pm2Env['args']) : (string)$pm2Env['args'];
            }

            $processes[] = [
                'id' => $proc['pm_id'] ?? 0,
                'name' => $proc['name'] ?? 'unknown',
                'pid' => $proc['pid'] ?? 0,
                'status' => $pm2Env['status'] ?? 'stopped',
                'instances' => $pm2Env['instances'] ?? 1,
                'restarts' => $pm2Env['restart_time'] ?? 0,
                'uptime' => isset($pm2Env['pm_uptime']) && $pm2Env['pm_uptime'] > 0 
                    ? (int)(floor((time() * 1000 - $pm2Env['pm_uptime']) / 1000)) 
                    : 0,
                'cpu' => $monit['cpu'] ?? 0,
                'memory' => isset($monit['memory']) ? round($monit['memory'] / 1024 / 1024, 1) : 0, // MB
                'user' => $pm2Env['username'] ?? 'N/A',
                'script' => $pm2Env['pm_exec_path'] ?? '',
                'mode' => $pm2Env['exec_mode'] ?? 'fork_mode',
                'cwd' => $pm2Env['pm_cwd'] ?? '',
                'args' => $argsStr,
                'interpreter' => $pm2Env['exec_interpreter'] ?? 'node',
                'max_memory_restart' => isset($pm2Env['max_memory_restart']) ? (is_numeric($pm2Env['max_memory_restart']) ? round($pm2Env['max_memory_restart'] / 1024 / 1024) . 'M' : (string)$pm2Env['max_memory_restart']) : '',
                'autorestart' => $pm2Env['autorestart'] ?? true,
                'cron_restart' => $pm2Env['cron_restart'] ?? '',
                'restart_delay' => $pm2Env['restart_delay'] ?? 0,
                'output_log' => $pm2Env['pm_out_log_path'] ?? '',
                'error_log' => $pm2Env['pm_err_log_path'] ?? '',
                'log_date_format' => $pm2Env['log_date_format'] ?? '',
                'env' => $pm2Env['env'] ?? []
            ];
        }

        return $processes;
    }

    /**
     * Execute standard control action: start, stop, restart, reload, delete
     */
    public function executeAction(string $action, string $target): array
    {
        if (!$this->isInstalled()) {
            return ['success' => false, 'error' => 'PM2 is not installed on this system.'];
        }

        $allowedActions = ['start', 'stop', 'restart', 'reload', 'delete'];
        if (!in_array($action, $allowedActions, true)) {
            return ['success' => false, 'error' => 'Invalid PM2 action specified.'];
        }

        // Sanitize target (can be numeric ID, app name, or all)
        if ($target !== 'all' && !preg_match('/^[a-zA-Z0-9_\-\.\/]+$/', $target)) {
            return ['success' => false, 'error' => 'Invalid target process identifier.'];
        }

        $cmd = sprintf('%s %s %s 2>&1', escapeshellcmd($this->pm2Path), escapeshellarg($action), escapeshellarg($target));
        $output = \safeShellExec($cmd);

        return [
            'success' => true,
            'action' => $action,
            'target' => $target,
            'output' => trim((string)$output)
        ];
    }

    /**
     * Launch a new process via PM2
     */
    public function startApp(string $scriptOrName, ?string $name = null, ?string $cwd = null): array
    {
        if (!$this->isInstalled()) {
            return ['success' => false, 'error' => 'PM2 is not installed on this system.'];
        }

        if (empty($scriptOrName)) {
            return ['success' => false, 'error' => 'Script path or application name is required.'];
        }

        $cmd = escapeshellcmd($this->pm2Path) . ' start ' . escapeshellarg($scriptOrName);
        if (!empty($name)) {
            $cmd .= ' --name ' . escapeshellarg($name);
        }
        if (!empty($cwd)) {
            $cmd .= ' --cwd ' . escapeshellarg($cwd);
        }
        $cmd .= ' 2>&1';

        $output = \safeShellExec($cmd);
        return [
            'success' => true,
            'output' => trim((string)$output)
        ];
    }

    /**
     * Fetch process logs
     */
    public function getLogs(string $target, int $lines = 100): string
    {
        if (!$this->isInstalled()) {
            return 'PM2 is not installed on this system.';
        }

        if ($target !== 'all' && !preg_match('/^[a-zA-Z0-9_\-\.]+$/', $target)) {
            return 'Invalid target process specified.';
        }

        $lines = max(1, min(500, $lines));
        $cmd = sprintf('%s logs %s --lines %d --nostream 2>&1', escapeshellcmd($this->pm2Path), escapeshellarg($target), $lines);
        $output = \safeShellExec($cmd);

        // Strip ANSI escape sequences for clean browser output
        $clean = preg_replace('/\x1b\[[0-9;]*[mGKB]/', '', (string)$output) ?: 'No log output returned.';
        return $clean;
    }

    /**
     * Auto-install PM2 globally using npm if npm is present
     */
    public function autoInstall(): array
    {
        $npmPath = $this->findNpmPath();
        if (empty($npmPath)) {
            return ['success' => false, 'error' => 'Node.js / npm is not installed on this server. Please install Node.js and npm first.'];
        }

        $cmd = escapeshellcmd($npmPath) . ' install -g pm2 2>&1';
        $output = \safeShellExec($cmd);

        // Re-discover pm2 path after install
        $this->__construct();

        if ($this->isInstalled()) {
            return ['success' => true, 'message' => 'PM2 installed successfully globally via npm!', 'output' => $output];
        }

        return ['success' => false, 'error' => 'PM2 installation failed. Output: ' . substr((string)$output, 0, 300)];
    }

    /**
     * Discover npm binary path across system and NVM installations
     */
    private function findNpmPath(): string
    {
        // 1. Try `which npm`
        $path = trim((string)\safeShellExec('which npm 2>/dev/null'));
        if (!empty($path) && file_exists($path)) {
            return $path;
        }

        // 2. Search common locations and NVM paths
        $nvmPaths = array_merge(
            glob('/root/.nvm/versions/node/*/bin/npm') ?: [],
            glob((getenv('HOME') ?: '/root') . '/.nvm/versions/node/*/bin/npm') ?: [],
            glob('/home/*/.nvm/versions/node/*/bin/npm') ?: []
        );

        $candidates = array_merge([
            '/usr/local/bin/npm',
            '/usr/bin/npm',
            '/opt/node/bin/npm',
            getenv('HOME') . '/.npm-global/bin/npm',
            '/root/.npm-global/bin/npm'
        ], $nvmPaths);

        foreach ($candidates as $candidate) {
            if (!empty($candidate) && file_exists($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return '';
    }

    /**
     * Update PM2 Process settings with full PM2 options suite
     */
    public function updateProcessConfig(array $opts): array
    {
        if (!$this->isInstalled()) {
            return ['success' => false, 'error' => 'PM2 is not installed on this system.'];
        }

        $name = trim((string)($opts['name'] ?? ''));
        $script = trim((string)($opts['script'] ?? ''));

        if (empty($name) || empty($script)) {
            return ['success' => false, 'error' => 'Process name and script path are required.'];
        }

        $cmdParts = [];
        $cmdParts[] = escapeshellcmd($this->pm2Path) . ' delete ' . escapeshellarg($name) . ' 2>&1;';

        $startCmd = escapeshellcmd($this->pm2Path) . ' start ' . escapeshellarg($script) . ' --name ' . escapeshellarg($name);

        if (!empty($opts['cwd']) && is_dir($opts['cwd'])) {
            $startCmd .= ' --cwd ' . escapeshellarg($opts['cwd']);
        }
        if (!empty($opts['args'])) {
            $startCmd .= ' -- ' . $opts['args'];
        }
        if (!empty($opts['interpreter'])) {
            $startCmd .= ' --interpreter ' . escapeshellarg($opts['interpreter']);
        }
        if (!empty($opts['instances'])) {
            $startCmd .= ' -i ' . escapeshellarg((string)$opts['instances']);
        }
        if (!empty($opts['max_memory_restart'])) {
            $startCmd .= ' --max-memory-restart ' . escapeshellarg($opts['max_memory_restart']);
        }
        if (isset($opts['autorestart']) && ($opts['autorestart'] === false || $opts['autorestart'] === 'false' || $opts['autorestart'] === '0')) {
            $startCmd .= ' --no-autorestart';
        }
        if (!empty($opts['cron_restart'])) {
            $startCmd .= ' --cron ' . escapeshellarg($opts['cron_restart']);
        }
        if (!empty($opts['restart_delay']) && (int)$opts['restart_delay'] > 0) {
            $startCmd .= ' --restart-delay ' . (int)$opts['restart_delay'];
        }
        if (!empty($opts['output_log'])) {
            $startCmd .= ' -o ' . escapeshellarg($opts['output_log']);
        }
        if (!empty($opts['error_log'])) {
            $startCmd .= ' -e ' . escapeshellarg($opts['error_log']);
        }
        if (!empty($opts['log_date_format'])) {
            $startCmd .= ' --log-date-format ' . escapeshellarg($opts['log_date_format']);
        }

        // Environment variables
        if (!empty($opts['env']) && is_array($opts['env'])) {
            $envPrefix = '';
            foreach ($opts['env'] as $k => $v) {
                $kClean = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$k);
                if (!empty($kClean)) {
                    $envPrefix .= $kClean . '=' . escapeshellarg((string)$v) . ' ';
                }
            }
            if (!empty($envPrefix)) {
                $startCmd = $envPrefix . $startCmd;
            }
        }

        $cmdParts[] = $startCmd . ' 2>&1;';
        $cmdParts[] = escapeshellcmd($this->pm2Path) . ' save 2>&1';

        $fullCmd = implode(' ', $cmdParts);
        $output = \safeShellExec($fullCmd);

        return [
            'success' => true,
            'name' => $name,
            'output' => trim((string)$output)
        ];
    }
}
