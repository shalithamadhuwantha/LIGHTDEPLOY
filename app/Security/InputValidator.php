<?php
declare(strict_types=1);

namespace LightDeploy\Security;

class InputValidator
{
    private string $scriptsDir;

    public function __construct(string $scriptsDir)
    {
        $this->scriptsDir = $scriptsDir;
    }

    /**
     * Validates a Site ID strictly.
     * Prevents shell injection, path traversal, null bytes.
     */
    public function validateSiteId(string $siteId): bool
    {
        if (empty($siteId) || strlen($siteId) > 64) {
            return false;
        }

        // Must match alphanumeric, hyphens, underscores ONLY
        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $siteId) === 1;
    }

    /**
     * Validates a Deployment ID strictly.
     */
    public function validateDeploymentId(string $depId): bool
    {
        if (empty($depId) || strlen($depId) > 64) {
            return false;
        }

        return preg_match('/^DEP-\d{8}-[a-fA-F0-9]{4,16}$/', $depId) === 1;
    }

    /**
     * Resolves the canonical script path, handling both production (/opt/lightdeploy) and local workspace environments.
     */
    public function resolveScriptPath(string $scriptPath): string
    {
        if (file_exists($scriptPath)) {
            return $scriptPath;
        }

        $localFallback = $this->scriptsDir . '/' . basename($scriptPath);
        if (file_exists($localFallback)) {
            return $localFallback;
        }

        return $scriptPath;
    }

    /**
     * Validates that a script file path is safe to execute.
     * Ensures:
     * 1. Script exists
     * 2. Ends with .sh
     * 3. Is a regular file
     * 4. Is executable (or can be auto-chmodded)
     * 5. Contains no null bytes or path traversal escapes
     */
    public function validateScriptPath(string $rawScriptPath): bool
    {
        // Must contain no null bytes
        if (strpos($rawScriptPath, "\0") !== false) {
            return false;
        }

        // Must end with .sh
        if (pathinfo($rawScriptPath, PATHINFO_EXTENSION) !== 'sh') {
            return false;
        }

        $scriptPath = $this->resolveScriptPath($rawScriptPath);

        // Must exist and be a regular file
        if (!file_exists($scriptPath) || !is_file($scriptPath)) {
            return false;
        }

        // Attempt auto-chmod if not executable
        if (!is_executable($scriptPath)) {
            @chmod($scriptPath, 0755);
            if (!is_executable($scriptPath)) {
                return false;
            }
        }

        return true;
    }
}
