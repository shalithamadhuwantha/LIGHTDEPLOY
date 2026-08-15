<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API Front Controller Router
 * Routes /api/<endpoint> or /api/<endpoint>.php requests to app/Api/<endpoint>.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$endpoint = basename($uri);

// Strip .php extension if present
if (str_ends_with($endpoint, '.php')) {
    $endpoint = substr($endpoint, 0, -4);
}

$targetFile = dirname(__DIR__, 2) . '/app/Api/' . $endpoint . '.php';

if (file_exists($targetFile)) {
    require $targetFile;
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
