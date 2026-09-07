<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Admin terminal command allowlist
 * GET /api/terminal_commands
 * POST /api/terminal_commands
 * DELETE /api/terminal_commands
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Terminal\SiteTerminal;

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);
$admin = $authService->requireRole('admin');
$commandsFile = $config['config_dir'] . '/terminal_commands.json';

$data = safeReadJson($commandsFile, ['commands' => []]);
$customCommands = array_values(array_unique(array_filter(array_map(
    static fn($command) => SiteTerminal::normalizeCustomCommand((string)$command),
    is_array($data['commands'] ?? null) ? $data['commands'] : []
))));

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    jsonSuccess([
        'default_commands' => SiteTerminal::getDefaultCommands(),
        'custom_commands' => $customCommands
    ]);
}

if (!Csrf::validateHeaderOrPost()) {
    jsonError('CSRF_ERROR', 'Invalid or missing CSRF token.', 403);
}

$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$command = SiteTerminal::normalizeCustomCommand((string)($input['command'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($command === null) {
        jsonError('INVALID_COMMAND', 'Use a simple command with arguments only. Shell operators, paths, and redirects are not allowed.', 400);
    }

    if (in_array($command, SiteTerminal::getDefaultCommands(), true)) {
        jsonError('COMMAND_ALREADY_ALLOWED', 'This command is already built in.', 409);
    }

    if (!in_array($command, $customCommands, true)) {
        $customCommands[] = $command;
        sort($customCommands, SORT_NATURAL);
        if (!safeWriteJson($commandsFile, ['commands' => $customCommands])) {
            jsonError('WRITE_FAILED', 'Failed to save the terminal command.', 500);
        }
    }

    $securityLogger->log('TERMINAL_COMMAND_ADDED', ['command' => $command], $admin['username']);
    jsonSuccess(['command' => $command, 'custom_commands' => $customCommands], 201);
}

if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    if ($command === null || !in_array($command, $customCommands, true)) {
        jsonError('COMMAND_NOT_FOUND', 'Custom terminal command not found.', 404);
    }

    $customCommands = array_values(array_filter($customCommands, static fn($item) => $item !== $command));
    if (!safeWriteJson($commandsFile, ['commands' => $customCommands])) {
        jsonError('WRITE_FAILED', 'Failed to remove the terminal command.', 500);
    }

    $securityLogger->log('TERMINAL_COMMAND_REMOVED', ['command' => $command], $admin['username']);
    jsonSuccess(['command' => $command, 'custom_commands' => $customCommands]);
}

jsonError('METHOD_NOT_ALLOWED', 'Only GET, POST, and DELETE methods are allowed.', 405);