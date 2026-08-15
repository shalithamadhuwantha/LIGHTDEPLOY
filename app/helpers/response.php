<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Response Helpers
 * Standardized JSON responses for LightDeploy API
 */

if (!function_exists('jsonResponse')) {
    function jsonResponse(array $data, int $statusCode = 200): void
    {
        if (!headers_sent()) {
            http_response_code($statusCode);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }
        echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

if (!function_exists('jsonError')) {
    function jsonError(string $code, string $message, int $statusCode = 400, ?array $details = null): void
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ]
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        jsonResponse($payload, $statusCode);
    }
}

if (!function_exists('jsonSuccess')) {
    function jsonSuccess(array $data = [], int $statusCode = 200): void
    {
        $payload = array_merge(['success' => true], $data);
        jsonResponse($payload, $statusCode);
    }
}
