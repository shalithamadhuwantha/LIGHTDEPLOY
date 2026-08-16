<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;

class DeploymentService
{
    private InputValidator $validator;
    private DeploymentLock $lockManager;
    private DeploymentRunner $runner;
    private DeploymentLog $logger;
    private HealthChecker $healthChecker;
    private ?SecurityLogger $securityLogger;
    private array $config;

    public function __construct(
        InputValidator $validator,
        DeploymentLock $lockManager,
        DeploymentRunner $runner,
        DeploymentLog $logger,
        HealthChecker $healthChecker,
        ?SecurityLogger $securityLogger = null,
        array $config = []
    ) {
        $this->validator = $validator;
        $this->lockManager = $lockManager;
        $this->runner = $runner;
        $this->logger = $logger;
        $this->healthChecker = $healthChecker;
        $this->securityLogger = $securityLogger;
        $this->config = $config;
    }

    /**
     * Generates a unique deployment ID (e.g. DEP-20260815-a1b2c3d4).
     */
    public function generateDeploymentId(): string
    {
        $date = date('Ymd');
        $randomHex = bin2hex(random_bytes(4));
        return sprintf("DEP-%s-%s", $date, $randomHex);
    }

    /**
     * Triggers a new deployment workflow for a site.
     */
    public function startDeployment(string $siteId, array $siteConfig, string $user, bool $isRollback = false): array
    {
        // 1. Validate Site ID
        if (!$this->validator->validateSiteId($siteId)) {
            if ($this->securityLogger) {
                $this->securityLogger->log('MALICIOUS_INPUT_ATTEMPT', ['invalid_site_id' => $siteId], $user);
            }
            return [
                'success' => false,
                'error_code' => 'INVALID_SITE_ID',
                'message' => 'Invalid site identifier supplied.',
                'status_code' => 400
            ];
        }

        // 2. Determine target script (normal script vs rollback script)
        $scriptPath = $isRollback
            ? ($siteConfig['rollback_script'] ?? null)
            : ($siteConfig['script'] ?? null);

        if (empty($scriptPath)) {
            return [
                'success' => false,
                'error_code' => 'SCRIPT_NOT_CONFIGURED',
                'message' => $isRollback ? 'Rollback script not configured for this site.' : 'Deployment script not configured.',
                'status_code' => 400
            ];
        }

        // 3. Validate script path security (realpath, executable, inside approved dir, no escapes)
        if (!$this->validator->validateScriptPath($scriptPath)) {
            if ($this->securityLogger) {
                $this->securityLogger->log('SECURITY_SCRIPT_VALIDATION_FAILED', ['site_id' => $siteId, 'script' => $scriptPath], $user);
            }
            return [
                'success' => false,
                'error_code' => 'UNAUTHORIZED_SCRIPT',
                'message' => 'Configured script failed path security and allowlist validation.',
                'status_code' => 403
            ];
        }

        // 4. Check concurrency lock
        if ($this->lockManager->isLocked($siteId)) {
            $lockInfo = $this->lockManager->getLockInfo($siteId);
            return [
                'success' => false,
                'error_code' => 'DEPLOYMENT_ALREADY_RUNNING',
                'message' => 'A deployment for this site is currently in progress.',
                'status_code' => 409,
                'details' => $lockInfo
            ];
        }

        // 5. Generate Deployment ID
        $deploymentId = $this->generateDeploymentId();

        // 6. Spawn deployment process
        $resolvedScriptPath = $this->validator->resolveScriptPath($scriptPath);
        $spawnResult = $this->runner->startProcess($deploymentId, $resolvedScriptPath, [
            'SITE_ID' => $siteId,
            'SITE_NAME' => $siteConfig['name'] ?? $siteId,
            'SITE_DOMAIN' => $siteConfig['domain'] ?? '',
            'DEPLOYED_BY' => $user,
            'IS_ROLLBACK' => $isRollback ? '1' : '0'
        ]);

        if (!$spawnResult['success']) {
            return [
                'success' => false,
                'error_code' => 'PROCESS_SPAWN_FAILED',
                'message' => $spawnResult['error'] ?? 'Failed to execute deployment process.',
                'status_code' => 500
            ];
        }

        $pid = $spawnResult['pid'];

        // 7. Acquire concurrency lock
        $this->lockManager->acquire($siteId, $deploymentId, $user, $pid);

        // 8. Log security event
        $eventType = $isRollback ? 'ROLLBACK_STARTED' : 'DEPLOY_STARTED';
        if ($this->securityLogger) {
            $this->securityLogger->log($eventType, [
                'deployment_id' => $deploymentId,
                'site_id' => $siteId,
                'pid' => $pid
            ], $user);
        }

        // 9. Initial metadata save
        $meta = [
            'deployment_id' => $deploymentId,
            'site_id' => $siteId,
            'site_name' => $siteConfig['name'] ?? $siteId,
            'domain' => $siteConfig['domain'] ?? '',
            'user' => $user,
            'is_rollback' => $isRollback,
            'start_time_ts' => time(),
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => null,
            'duration' => null,
            'status' => 'running',
            'exit_code' => null,
            'health_check' => null,
            'pid' => $pid
        ];

        safeWriteJson($this->runner->getJobPath($deploymentId), $meta);

        return [
            'success' => true,
            'deployment_id' => $deploymentId,
            'site_id' => $siteId,
            'status' => 'running',
            'pid' => $pid
        ];
    }

    /**
     * Checks status, monitors timeouts, executes post-healthchecks, handles completion.
     */
    public function updateDeploymentState(string $deploymentId, ?array $siteConfig = null): array
    {
        $jobPath = $this->runner->getJobPath($deploymentId);
        if (!file_exists($jobPath)) {
            return [
                'success' => false,
                'error_code' => 'DEPLOYMENT_NOT_FOUND',
                'message' => "Deployment ID {$deploymentId} not found.",
                'status_code' => 404
            ];
        }

        $meta = safeReadJson($jobPath, []);
        $status = $meta['status'] ?? 'unknown';
        $siteId = $meta['site_id'] ?? '';

        if ($status === 'running') {
            $startTime = (int)($meta['start_time_ts'] ?? time());
            $timeout = (int)($this->config['security']['default_deployment_timeout'] ?? 1800);
            $pid = (int)($meta['pid'] ?? 0);

            // Timeout Check
            if ((time() - $startTime) > $timeout) {
                $this->runner->terminateProcess($deploymentId);
                $meta['status'] = 'timeout';
                $meta['exit_code'] = 124;
                $meta['end_time'] = date('Y-m-d H:i:s');
                $meta['duration'] = time() - $startTime;
                
                $this->finalizeDeployment($meta, $siteId);
                return $meta;
            }

            // Check if process finished
            if (!$this->runner->isPidRunning($pid)) {
                $streamPath = $this->runner->getStreamPath($deploymentId);
                $streamContent = file_exists($streamPath) ? file_get_contents($streamPath) : '';

                // Read exit status if logged or default to 0/1
                $exitCode = 0;
                if (preg_match('#\[EXIT_CODE:(\d+)\]#', $streamContent, $matches)) {
                    $exitCode = (int)$matches[1];
                } elseif (strpos($streamContent, '[ERROR]') !== false || strpos($streamContent, 'FATAL') !== false) {
                    $exitCode = 1;
                }

                $meta['end_time'] = date('Y-m-d H:i:s');
                $meta['duration'] = time() - $startTime;
                $meta['exit_code'] = $exitCode;

                if ($exitCode !== 0) {
                    $meta['status'] = 'failed';
                    $this->finalizeDeployment($meta, $siteId);
                    return $meta;
                }

                // Script succeeded! Run Health Check if configured
                $healthResult = null;
                if ($siteConfig && !empty($siteConfig['health_check_enabled']) && !empty($siteConfig['health_check'])) {
                    @file_put_contents($streamPath, "[" . date('H:i:s') . "] [HEALTH] Performing post-deployment health check on " . $siteConfig['health_check'] . "...\n", FILE_APPEND);
                    $healthResult = $this->healthChecker->check($siteConfig['health_check']);
                    $meta['health_check'] = $healthResult;

                    if ($healthResult['success']) {
                        @file_put_contents($streamPath, "[" . date('H:i:s') . "] [HEALTH] Health check PASSED (" . $healthResult['message'] . ")\n", FILE_APPEND);
                        $meta['status'] = 'success';
                    } else {
                        @file_put_contents($streamPath, "[" . date('H:i:s') . "] [HEALTH] Health check FAILED (" . $healthResult['message'] . ")\n", FILE_APPEND);
                        $meta['status'] = 'health_check_failed';
                    }
                } else {
                    $meta['status'] = 'success';
                }

                $this->finalizeDeployment($meta, $siteId);
            }
        }

        return $meta;
    }

    private function finalizeDeployment(array $meta, string $siteId): void
    {
        $deploymentId = $meta['deployment_id'];
        $streamPath = $this->runner->getStreamPath($deploymentId);
        $streamContent = file_exists($streamPath) ? file_get_contents($streamPath) : '';

        // Auto-generate crystal-clear Diagnostic Error Summary on failure
        if (in_array($meta['status'], ['failed', 'health_check_failed', 'timeout'], true) && strpos($streamContent, '[DIAGNOSTIC SUMMARY]') === false) {
            $exitCode = $meta['exit_code'] ?? 1;
            $lines = explode("\n", trim($streamContent));
            $detectedErrors = [];
            foreach ($lines as $line) {
                if (preg_match('/(error|failed|command not found|permission denied|no such file|syntax error|fatal|cannot open|invalid|denied)/i', $line)) {
                    $detectedErrors[] = trim($line);
                }
            }

            $diag = "\n" . str_repeat('=', 80) . "\n";
            $diag .= "[" . date('H:i:s') . "] 🔴 [DIAGNOSTIC SUMMARY] EXECUTION FAILED (Status: " . strtoupper((string)$meta['status']) . " | Exit Code: {$exitCode})\n";
            $diag .= str_repeat('-', 80) . "\n";
            if (!empty($detectedErrors)) {
                $diag .= "  Primary Root Cause Highlights:\n";
                foreach (array_slice(array_unique($detectedErrors), -5) as $errLine) {
                    $diag .= "   • {$errLine}\n";
                }
            } else {
                $lastLines = array_slice(array_filter($lines, 'trim'), -3);
                $diag .= "  Last Execution Output Captured:\n";
                foreach ($lastLines as $lastLine) {
                    $diag .= "   • {$lastLine}\n";
                }
            }
            $diag .= "  Recommended Fixes:\n";
            $diag .= "   1. Check script location, execute permissions (chmod +x), and directory ownership.\n";
            $diag .= "   2. Ensure target CLI commands (node, php, python3, git, npm) exist in system PATH.\n";
            $diag .= str_repeat('=', 80) . "\n\n";

            @file_put_contents($streamPath, $diag, FILE_APPEND);
            $streamContent .= $diag;
        }

        // Save final job json
        safeWriteJson($this->runner->getJobPath($deploymentId), $meta);

        // Save permanent log
        $this->logger->saveDeployment($meta, $streamContent);

        // Release lock
        $this->lockManager->release($siteId);

        // Log audit event
        if ($this->securityLogger) {
            $evt = match ($meta['status']) {
                'success' => $meta['is_rollback'] ? 'ROLLBACK_SUCCESS' : 'DEPLOY_SUCCESS',
                'failed' => $meta['is_rollback'] ? 'ROLLBACK_FAILED' : 'DEPLOY_FAILED',
                'cancelled' => 'DEPLOY_CANCELLED',
                default => 'DEPLOY_' . strtoupper($meta['status'])
            };
            $this->securityLogger->log($evt, [
                'deployment_id' => $deploymentId,
                'site_id' => $siteId,
                'status' => $meta['status'],
                'duration' => $meta['duration'] ?? 0
            ], $meta['user'] ?? 'SYSTEM');
        }
    }

    public function cancelDeployment(string $deploymentId, string $user): array
    {
        if (!$this->validator->validateDeploymentId($deploymentId)) {
            return [
                'success' => false,
                'error_code' => 'INVALID_DEPLOYMENT_ID',
                'message' => 'Invalid deployment identifier format.',
                'status_code' => 400
            ];
        }

        $jobPath = $this->runner->getJobPath($deploymentId);
        if (!file_exists($jobPath)) {
            return [
                'success' => false,
                'error_code' => 'DEPLOYMENT_NOT_FOUND',
                'message' => 'Deployment not found.',
                'status_code' => 404
            ];
        }

        $meta = safeReadJson($jobPath, []);
        $siteId = $meta['site_id'] ?? '';

        $this->runner->terminateProcess($deploymentId);

        $meta['status'] = 'cancelled';
        $meta['end_time'] = date('Y-m-d H:i:s');
        $meta['duration'] = time() - (int)($meta['start_time_ts'] ?? time());
        $meta['exit_code'] = 130;

        $this->finalizeDeployment($meta, $siteId);

        return [
            'success' => true,
            'message' => 'Deployment process terminated successfully.',
            'deployment_id' => $deploymentId
        ];
    }
}
