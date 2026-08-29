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
}
