<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Bootstrap
 * Initializes security settings, error handling, autoloading, and sessions.
 */

// Set default system timezone to Sri Lanka (Asia/Colombo)
date_default_timezone_set('Asia/Colombo');

// Error handling settings for production security
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);

$rootDir = dirname(__DIR__);
$errorLogDir = $rootDir . '/logs/application';
if (!is_dir($errorLogDir)) {
    @mkdir($errorLogDir, 0755, true);
}
ini_set('error_log', $errorLogDir . '/error.log');

// Load helper functions
require_once __DIR__ . '/helpers/response.php';
require_once __DIR__ . '/helpers/filesystem.php';
require_once __DIR__ . '/helpers/validation.php';

// Load configuration
$config = require __DIR__ . '/config.php';

// PSR-4 style autoloader for LightDeploy namespace
spl_autoload_register(function (string $class) use ($rootDir) {
    $prefix = 'LightDeploy\\';
    $baseDir = $rootDir . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

// Set Security HTTP Headers
if (!headers_sent()) {
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none';");
    header("X-Content-Type-Options: nosniff");
    header("X-Frame-Options: DENY");
    header("X-XSS-Protection: 1; mode=block");
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header("Permissions-Policy: geolocation=(), camera=(), microphone=()");
    if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
        header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
    }
}

// Secure Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ||
               (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    $sessionSavePath = $rootDir . '/runtime/sessions';
    if (!is_dir($sessionSavePath)) {
        @mkdir($sessionSavePath, 0700, true);
    }
    if (is_writable($sessionSavePath)) {
        session_save_path($sessionSavePath);
    }

    session_name('LIGHTDEPLOY_SESS');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Strict'
    ]);
    session_start();
}

return $config;
