<?php
declare(strict_types=1);

namespace LightDeploy\Auth;

class Csrf
{
    public static function getToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function validateToken(?string $token): bool
    {
        if (empty($token) || empty($_SESSION['csrf_token'])) {
            return false;
        }

        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function validateHeaderOrPost(): bool
    {
        if (self::isValidManagedNodeBearer()) {
            return true;
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;

        if (!$token && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $key => $val) {
                if (strtolower((string)$key) === 'x-csrf-token') {
                    $token = (string)$val;
                    break;
                }
            }
        }

        if (!$token && str_contains(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
            $rawInput = file_get_contents('php://input');
            if ($rawInput) {
                $input = json_decode($rawInput, true);
                $token = $input['csrf_token'] ?? null;
            }
        }

        if (!$token && isset($_POST['csrf_token'])) {
            $token = (string)$_POST['csrf_token'];
        }

        return self::validateToken($token);
    }

    private static function isValidManagedNodeBearer(): bool
    {
        $authorization = (string)($_SERVER['HTTP_X_LIGHTDEPLOY_NODE_TOKEN'] ?? $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        if ($authorization !== '' && !str_starts_with($authorization, 'Bearer ')) {
            $authorization = 'Bearer ' . $authorization;
        }
        if ($authorization === '' && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strtolower((string)$name) === 'authorization') {
                    $authorization = (string)$value;
                    break;
                }
            }
        }
        if (!preg_match('/^Bearer\\s+([A-Za-z0-9_-]{48,})$/', $authorization, $matches)) {
            return false;
        }

        $configFile = dirname(__DIR__, 2) . '/config/node_control.json';
        $nodeConfig = safeReadJson($configFile, []);
        $storedHash = (string)($nodeConfig['token_hash'] ?? '');
        return $storedHash !== '' && hash_equals($storedHash, hash('sha256', $matches[1]));
    }
}
