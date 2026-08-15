<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Router for PHP Built-in Local Development Server
 * Usage: php -S 127.0.0.1:8000 -t public public/router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve static assets (CSS, JS, images, etc.) directly if file exists
$filePath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($filePath) && !is_dir($filePath)) {
    return false; // Hand off to built-in web server
}

// Route /api/* requests
if (str_starts_with($uri, '/api/')) {
    $endpoint = substr($uri, 5); // Strip '/api/'
    if (str_ends_with($endpoint, '.php')) {
        $endpoint = substr($endpoint, 0, -4);
    }

    $apiFile = dirname(__DIR__) . '/app/Api/' . $endpoint . '.php';
    if (file_exists($apiFile)) {
        require $apiFile;
        exit;
    }

    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode([
        'success' => false,
        'error' => [
            'code' => 'ENDPOINT_NOT_FOUND',
            'message' => "API endpoint '{$endpoint}' was not found."
        ]
    ]);
    exit;
}

// Route Views
if ($uri === '/login' || $uri === '/login.php') {
    require __DIR__ . '/login.php';
    exit;
}

require __DIR__ . '/index.php';
