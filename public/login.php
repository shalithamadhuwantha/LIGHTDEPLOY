<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY Login Page
 */

$config = require_once dirname(__DIR__) . '/app/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;

$authService = new AuthService($config['config_dir'] . '/users.json');

if ($authService->isAuthenticated()) {
    header('Location: /index.php');
    exit;
}

$csrfToken = Csrf::getToken();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LightDeploy - Lightweight Secure Web Deployment Panel</title>
    <link rel="stylesheet" href="/assets/app.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
</head>
<body class="login-body">
    <div class="login-bg-glow"></div>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <div class="logo-badge">
                    <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round">
                        <polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon>
                    </svg>
                </div>
                <h1>LIGHTDEPLOY</h1>
                <p>Secure Lightweight Web Deployment Panel</p>
            </div>

            <div id="alertBox" class="alert-box hidden"></div>

            <form id="loginForm" autocomplete="off">
                <input type="hidden" id="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                
                <div class="form-group">
                    <label for="username">Username</label>
                    <div class="input-wrapper">
                        <input type="text" id="username" name="username" placeholder="Enter your username" required autofocus>
                    </div>
                </div>

                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrapper">
                        <input type="password" id="password" name="password" placeholder="Enter your password" required>
                    </div>
                </div>

                <button type="submit" id="loginBtn" class="btn btn-primary btn-block">
                    <span class="btn-text">Sign In to Dashboard</span>
                    <span class="spinner hidden"></span>
                </button>
            </form>

            <div class="login-footer">
                <span>aaPanel &bull; PHP 8.x &bull; SSE Engine</span>
            </div>
        </div>
    </div>

    <script>
        document.getElementById('loginForm').addEventListener('submit', async (e) => {
            e.preventDefault();
            const alertBox = document.getElementById('alertBox');
            const btn = document.getElementById('loginBtn');
            const btnText = btn.querySelector('.btn-text');
            const spinner = btn.querySelector('.spinner');

            alertBox.classList.add('hidden');
            btn.disabled = true;
            btnText.textContent = 'Authenticating...';
            spinner.classList.remove('hidden');

            try {
                const res = await fetch('/api/login.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        username: document.getElementById('username').value,
                        password: document.getElementById('password').value,
                        csrf_token: document.getElementById('csrf_token').value
                    })
                });

                const data = await res.json();

                if (res.ok && data.success) {
                    window.location.href = '/index.php';
                } else {
                    alertBox.textContent = data.error?.message || 'Login failed.';
                    alertBox.className = 'alert-box alert-danger';
                    alertBox.classList.remove('hidden');
                }
            } catch (err) {
                alertBox.textContent = 'Network error. Please check connection.';
                alertBox.className = 'alert-box alert-danger';
                alertBox.classList.remove('hidden');
            } finally {
                btn.disabled = false;
                btnText.textContent = 'Sign In to Dashboard';
                spinner.classList.add('hidden');
            }
        });
    </script>
</body>
</html>
