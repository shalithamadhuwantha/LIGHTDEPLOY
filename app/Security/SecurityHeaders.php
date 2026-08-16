<?php
declare(strict_types=1);

namespace LightDeploy\Security;

/**
 * LIGHTDEPLOY HTTP Security Response Headers Manager
 */
class SecurityHeaders
{
    /**
     * Apply default security headers to current HTTP response
     */
    public static function apply(): void
    {
        if (headers_sent()) {
            return;
        }

        // Clickjacking protection
        header('X-Frame-Options: DENY');

        // Prevent MIME-type sniffing
        header('X-Content-Type-Options: nosniff');

        // XSS Protection for legacy browsers
        header('X-XSS-Protection: 1; mode=block');

        // Protect referrer leakage
        header('Referrer-Policy: strict-origin-when-cross-origin');

        // Restrict browser hardware capabilities
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');

        // Content Security Policy
        $csp = "default-src 'self'; " .
               "script-src 'self' 'unsafe-inline'; " .
               "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; " .
               "font-src 'self' https://fonts.gstatic.com; " .
               "img-src 'self' data:; " .
               "connect-src 'self'; " .
               "frame-ancestors 'none';";

        header("Content-Security-Policy: {$csp}");
    }
}
