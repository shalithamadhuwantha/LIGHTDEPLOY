<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Validation Helpers
 * Input sanitization and format validation functions.
 */

if (!function_exists('sanitizeString')) {
    function sanitizeString(string $input): string
    {
        return htmlspecialchars(trim($input), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('isValidSiteId')) {
    function isValidSiteId(string $siteId): bool
    {
        // Alphanumeric, underscores, hyphens, 1-64 chars
        return preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $siteId) === 1;
    }
}

if (!function_exists('isValidDeploymentId')) {
    function isValidDeploymentId(string $depId): bool
    {
        // DEP-YYYYMMDD-HEX (e.g. DEP-20260815-a1b2c3d4)
        return preg_match('/^DEP-\d{8}-[a-fA-F0-9]{4,16}$/', $depId) === 1;
    }
}
