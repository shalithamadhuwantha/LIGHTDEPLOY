<?php
declare(strict_types=1);

namespace LightDeploy\Auth;

use LightDeploy\Security\SecurityLogger;

class AuthService
{
    private string $usersConfigFile;
    private ?SecurityLogger $logger;

    public function __construct(string $usersConfigFile, ?SecurityLogger $logger = null)
    {
        $this->usersConfigFile = $usersConfigFile;
        $this->logger = $logger;
    }

    public function getUsers(): array
    {
        return safeReadJson($this->usersConfigFile, ['users' => []]);
    }

    public function authenticate(string $username, string $password): ?array
    {
        $data = $this->getUsers();
        $users = $data['users'] ?? [];

        foreach ($users as $u => $userData) {
            if (hash_equals(strtolower($u), strtolower($username))) {
                if (password_verify($password, $userData['password_hash'])) {
                    return [
                        'username' => $u,
                        'role' => $userData['role'] ?? 'viewer',
                        'name' => $userData['name'] ?? $u,
                    ];
                }
            }
        }

        return null;
    }

    public function login(string $username, string $password): bool
    {
        $user = $this->authenticate($username, $password);

        if ($user) {
            // Prevent Session Fixation: Regenerate session ID and delete old session
            session_regenerate_id(true);

            $_SESSION['authenticated'] = true;
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            $_SESSION['name'] = $user['name'];
            $_SESSION['login_time'] = time();

            // Generate CSRF token for session
            Csrf::getToken();

            if ($this->logger) {
                $this->logger->log('LOGIN_SUCCESS', ['username' => $user['username'], 'role' => $user['role']], $user['username']);
            }

            return true;
        }

        if ($this->logger) {
            $this->logger->log('LOGIN_FAILURE', ['username' => $username]);
        }

        return false;
    }

    public function logout(): void
    {
        if ($this->logger && !empty($_SESSION['username'])) {
            $this->logger->log('LOGOUT', [], $_SESSION['username']);
        }

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }

        session_destroy();
    }

    public function isAuthenticated(): bool
    {
        return !empty($_SESSION['authenticated']) && $_SESSION['authenticated'] === true;
    }

    public function getCurrentUser(): ?array
    {
        if (!$this->isAuthenticated()) {
            return null;
        }

        return [
            'username' => $_SESSION['username'] ?? '',
            'role' => $_SESSION['role'] ?? 'viewer',
            'name' => $_SESSION['name'] ?? '',
        ];
    }

    public function hasRole($roles): bool
    {
        if (!$this->isAuthenticated()) {
            return false;
        }

        $userRole = $_SESSION['role'] ?? 'viewer';

        if (is_array($roles)) {
            return in_array($userRole, $roles, true);
        }

        return $userRole === $roles;
    }

    public function requireAuth(): array
    {
        if (!$this->isAuthenticated()) {
            if ($this->logger) {
                $this->logger->log('UNAUTHORIZED_ACCESS', ['uri' => $_SERVER['REQUEST_URI'] ?? '']);
            }
            jsonError('UNAUTHORIZED', 'Authentication required.', 401);
        }

        return $this->getCurrentUser();
    }

    public function requireRole($roles): array
    {
        $user = $this->requireAuth();

        if (!$this->hasRole($roles)) {
            if ($this->logger) {
                $this->logger->log('FORBIDDEN_ROLE_ACCESS', [
                    'required' => $roles,
                    'user_role' => $user['role'],
                    'uri' => $_SERVER['REQUEST_URI'] ?? ''
                ], $user['username']);
            }
            jsonError('FORBIDDEN', 'Insufficient permissions for this action.', 403);
        }

        return $user;
    }
}
