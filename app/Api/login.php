<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Login
 * POST /api/login
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Security\RateLimiter;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$rateLimiter = new RateLimiter($config['runtime_dir'] . '/locks');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);

$ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$rateKey = 'login_ip_' . $ip;

$maxAttempts = (int)($config['security']['login_rate_limit_attempts'] ?? 5);
$window = (int)($config['security']['login_rate_limit_window'] ?? 900);

if (!$rateLimiter->isAllowed($rateKey, $maxAttempts, $window)) {
    $securityLogger->log('RATE_LIMIT', ['endpoint' => 'login', 'ip' => $ip]);
    jsonError('TOO_MANY_REQUESTS', 'Too many failed login attempts. Please wait before trying again.', 429);
}

$rawInput = file_get_contents('php://input');
$input = json_decode($rawInput, true) ?: $_POST;

$username = trim((string)($input['username'] ?? ''));
$password = (string)($input['password'] ?? '');

if (empty($username) || empty($password)) {
    jsonError('INVALID_INPUT', 'Username and password are required.', 400);
}

if ($authService->login($username, $password)) {
    $rateLimiter->clear($rateKey);
    $user = $authService->getCurrentUser();
    $csrfToken = Csrf::getToken();

    jsonSuccess([
        'message' => 'Authenticated successfully.',
        'user' => $user,
        'csrf_token' => $csrfToken
    ]);
} else {
    $rateLimiter->hit($rateKey, $window);
    jsonError('INVALID_CREDENTIALS', 'Invalid username or password.', 401);
}
