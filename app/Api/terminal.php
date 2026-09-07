<?php
declare(strict_types=1);

/**
 * LIGHTDEPLOY API: Restricted per-site terminal
 * POST /api/terminal
 */

$config = require_once dirname(__DIR__) . '/bootstrap.php';

use LightDeploy\Auth\AuthService;
use LightDeploy\Auth\Csrf;
use LightDeploy\Security\InputValidator;
use LightDeploy\Security\SecurityLogger;
use LightDeploy\Terminal\SiteTerminal;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonError('METHOD_NOT_ALLOWED', 'Only POST requests are permitted.', 405);
}

$securityLogger = new SecurityLogger($config['logs_dir'] . '/security');
$authService = new AuthService($config['config_dir'] . '/users.json', $securityLogger);
$user = $authService->requirePermission('terminal');

if (!Csrf::validateHeaderOrPost()) {
    $securityLogger->log('CSRF_FAILURE', ['endpoint' => 'terminal'], $user['username']);
    jsonError('CSRF_FAILURE', 'Invalid or missing CSRF security token.', 403);
}

$input = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$siteId = trim((string)($input['site_id'] ?? ''));
$command = trim((string)($input['command'] ?? ''));
$validator = new InputValidator($config['scripts_dir']);

if (!$validator->validateSiteId($siteId)) {
    jsonError('INVALID_SITE_ID', 'Invalid site identifier.', 400);
}

$authService->requireSystemAccess($siteId);
$sitesData = safeReadJson($config['config_dir'] . '/sites.json', ['sites' => []]);
$commandsData = safeReadJson($config['config_dir'] . '/terminal_commands.json', ['commands' => []]);
$customCommands = array_values(array_filter(array_map(
    static fn($command) => SiteTerminal::normalizeCustomCommand((string)$command),
    is_array($commandsData['commands'] ?? null) ? $commandsData['commands'] : []
)));
$siteConfig = $sitesData['sites'][$siteId] ?? null;
if (!is_array($siteConfig)) {
    jsonError('SITE_NOT_FOUND', 'The specified site is not configured.', 404);
}

if (empty($siteConfig['terminal_enabled'])) {
    jsonError('TERMINAL_DISABLED', 'The terminal is disabled for this site.', 403);
}

$argv = SiteTerminal::parseCommand($command, $customCommands);
if ($argv === null) {
    $securityLogger->log('TERMINAL_COMMAND_BLOCKED', ['site_id' => $siteId, 'command' => $command], $user['username']);
    jsonError('COMMAND_NOT_ALLOWED', 'Only approved, non-shell terminal commands are allowed.', 400);
}

$workingDirectory = SiteTerminal::resolveWorkingDirectory($siteId, $siteConfig);
if ($workingDirectory === null) {
    jsonError('INVALID_TERMINAL_PATH', 'The site terminal path must exist inside /www/wwwroot.', 400);
}

$result = SiteTerminal::execute($argv, $workingDirectory);
$securityLogger->log('TERMINAL_COMMAND', [
    'site_id' => $siteId,
    'command' => implode(' ', $argv),
    'working_directory' => $workingDirectory,
    'exit_code' => $result['exit_code']
], $user['username']);

jsonSuccess([
    'site_id' => $siteId,
    'command' => implode(' ', $argv),
    'working_directory' => $workingDirectory,
    'exit_code' => $result['exit_code'],
    'output' => $result['output']
]);