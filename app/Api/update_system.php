<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API - 1-Click System Update from GitHub Repository
 * Repository: https://github.com/shalithamadhuwantha/LIGHTDEPLOY
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService->requirePermission('update_system');
$currentUser = $authService->getCurrentUser();

$repoOwner = 'shalithamadhuwantha';
$repoName = 'LIGHTDEPLOY';
$repoUrl = "https://github.com/{$repoOwner}/{$repoName}";
$currentVersion = 'v1.2.4';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    // Check GitHub for latest commit info
    $apiUrl = "https://api.github.com/repos/{$repoOwner}/{$repoName}/commits/main";
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_USERAGENT => 'LightDeploy-Updater/1.2.4',
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => 0
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $latestCommit = null;
    if ($httpCode === 200 && !empty($response)) {
        $data = json_decode($response, true);
        if (isset($data['sha'])) {
            $latestCommit = [
                'sha' => substr($data['sha'], 0, 7),
                'full_sha' => $data['sha'],
                'message' => $data['commit']['message'] ?? '',
                'author' => $data['commit']['author']['name'] ?? '',
                'date' => $data['commit']['author']['date'] ?? ''
            ];
        }
    }

    // Local git commit check if .git folder exists
    $localCommit = null;
    $rootDir = dirname(__DIR__, 2);
    if (is_dir($rootDir . '/.git')) {
        $localSha = safeShellExec("cd " . escapeshellarg($rootDir) . " && git rev-parse --short HEAD 2>/dev/null");
        if ($localSha) {
            $localCommit = trim($localSha);
        }
    }

    jsonSuccess([
        'current_version' => $currentVersion,
        'repository' => $repoUrl,
        'local_commit' => $localCommit,
        'latest_commit' => $latestCommit,
        'update_available' => ($localCommit && $latestCommit) ? ($localCommit !== $latestCommit['sha']) : true
    ]);
}

if ($method === 'POST') {
    // CSRF Check
    $headers = getallheaders();
    $token = $headers['X-CSRF-Token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf_token'] ?? '';
    if (!Csrf::validateToken((string)$token)) {
        jsonError('CSRF_INVALID', 'Invalid CSRF token.', 403);
    }

    $rootDir = dirname(__DIR__, 2);
    $logs = [];

    $logs[] = "Initializing LightDeploy System Updater...";
    $logs[] = "Target Directory: {$rootDir}";
    $logs[] = "GitHub Repo: {$repoUrl}";

    // Safety backup of config and custom scripts
    $backupStamp = date('Ymd_His');
    $backupDir = $rootDir . '/backups/pre_update_' . $backupStamp;
    @mkdir($backupDir, 0755, true);
    if (is_dir($rootDir . '/config')) {
        safeShellExec("cp -r " . escapeshellarg($rootDir . '/config') . " " . escapeshellarg($backupDir . '/') . " 2>&1");
    }
    if (is_dir($rootDir . '/scripts')) {
        safeShellExec("cp -r " . escapeshellarg($rootDir . '/scripts') . " " . escapeshellarg($backupDir . '/') . " 2>&1");
    }
    $logs[] = "Created safety backup at: backups/pre_update_{$backupStamp}/";

    $updateSuccess = false;

    // Strategy 1: Git Pull if .git directory exists
    if (is_dir($rootDir . '/.git')) {
        $logs[] = "Git repository detected. Running git pull origin main...";
        $gitPull = safeShellExec("cd " . escapeshellarg($rootDir) . " && git pull origin main 2>&1");
        if ($gitPull) $logs[] = trim($gitPull);

        if (str_contains(strtolower($gitPull ?? ''), 'already up to date') || 
            str_contains(strtolower($gitPull ?? ''), 'updating') || 
            str_contains(strtolower($gitPull ?? ''), 'fast-forward') ||
            str_contains(strtolower($gitPull ?? ''), 'files changed')) {
            $updateSuccess = true;
            $logs[] = "Git update completed successfully.";
        }
    }

    // Strategy 2: Download & Extract Release Zip from GitHub
    if (!$updateSuccess) {
        $logs[] = "Downloading release package from GitHub (https://github.com/{$repoOwner}/{$repoName}/archive/refs/heads/main.zip)...";
        $zipUrl = "https://github.com/{$repoOwner}/{$repoName}/archive/refs/heads/main.zip";
        $tmpZip = sys_get_temp_dir() . '/lightdeploy_update_' . time() . '.zip';
        $tmpExtract = sys_get_temp_dir() . '/lightdeploy_extract_' . time();

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $zipUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_USERAGENT => 'LightDeploy-Updater/1.2.4',
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0
        ]);
        $zipData = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200 && !empty($zipData)) {
            file_put_contents($tmpZip, $zipData);
            $logs[] = "Release package downloaded (" . round(strlen($zipData) / 1024, 1) . " KB). Extracting...";

            @mkdir($tmpExtract, 0755, true);
            $unzipOut = safeShellExec("unzip -q -o " . escapeshellarg($tmpZip) . " -d " . escapeshellarg($tmpExtract) . " 2>&1");
            if ($unzipOut) $logs[] = trim($unzipOut);

            $extractedSubdir = $tmpExtract . '/' . $repoName . '-main';
            if (!is_dir($extractedSubdir)) {
                $subdirs = glob($tmpExtract . '/*', GLOB_ONLYDIR);
                if (!empty($subdirs)) $extractedSubdir = $subdirs[0];
            }

            if (is_dir($extractedSubdir)) {
                $logs[] = "Updating core code directories (app/, public/, tests/)...";
                safeShellExec("cp -r " . escapeshellarg($extractedSubdir . '/app') . "/* " . escapeshellarg($rootDir . '/app/') . " 2>&1");
                safeShellExec("cp -r " . escapeshellarg($extractedSubdir . '/public') . "/* " . escapeshellarg($rootDir . '/public/') . " 2>&1");
                safeShellExec("cp -r " . escapeshellarg($extractedSubdir . '/tests') . "/* " . escapeshellarg($rootDir . '/tests/') . " 2>&1");
                
                if (file_exists($extractedSubdir . '/install.sh')) {
                    @copy($extractedSubdir . '/install.sh', $rootDir . '/install.sh');
                }
                if (file_exists($extractedSubdir . '/UPGRADE.md')) {
                    @copy($extractedSubdir . '/UPGRADE.md', $rootDir . '/UPGRADE.md');
                }

                $updateSuccess = true;
                $logs[] = "Source code files successfully updated from GitHub archive!";
            } else {
                $logs[] = "ERROR: Could not locate extracted source files in ZIP archive.";
            }

            @unlink($tmpZip);
            safeShellExec("rm -rf " . escapeshellarg($tmpExtract) . " 2>&1");
        } else {
            $logs[] = "ERROR: Failed to download ZIP package from GitHub (HTTP {$httpCode}).";
        }
    }

    // Schedule background non-blocking PM2 reload so active HTTP connection completes cleanly
    safeShellExec("(sleep 2 && pm2 reload lightdeploy) > /dev/null 2>&1 &");
    $logs[] = "Scheduled zero-downtime service reload (PM2) in background.";

    jsonSuccess([
        'message' => 'System successfully updated from GitHub repository!',
        'logs' => implode("\n", $logs)
    ]);
}
