<?php
declare(strict_types=1);

namespace LightDeploy\Terminal;

class SiteTerminal
{
    private const ROOT_PREFIX = '/www/wwwroot';

    public static function getDefaultCommands(): array
    {
        return [
            'pwd',
            'ls',
            'ls -a',
            'ls -l',
            'ls -la',
            'ls -al',
            'npm install',
            'npm ci',
            'npm test',
            'npm build',
            'npx prisma db push',
            'npx prisma migrate deploy'
        ];
    }

    public static function normalizeCustomCommand(string $command): ?string
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 160 || preg_match('/[^a-zA-Z0-9._@+\/:=\-\s]/', $command)) {
            return null;
        }

        $parts = preg_split('/\s+/', $command, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts || count($parts) > 8) {
            return null;
        }

        foreach ($parts as $part) {
            if ($part === '' || strlen($part) > 64 || str_contains($part, '/') || str_contains($part, ':=')) {
                return null;
            }
        }

        return implode(' ', $parts);
    }

    public static function resolveWorkingDirectory(string $siteId, array $siteConfig): ?string
    {
        $configuredPath = trim((string)($siteConfig['terminal_path'] ?? ''));
        $candidate = $configuredPath !== '' ? $configuredPath : self::ROOT_PREFIX . '/' . $siteId;
        $realPath = realpath($candidate);
        $root = realpath(self::ROOT_PREFIX);

        if ($realPath === false || $root === false || !is_dir($realPath)) {
            return null;
        }

        $rootPrefix = rtrim($root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($realPath === $root || !str_starts_with($realPath, $rootPrefix)) {
            return null;
        }

        return $realPath;
    }

    public static function parseCommand(string $command, array $customCommands = []): ?array
    {
        $command = trim($command);
        if ($command === '' || strlen($command) > 160 || preg_match('/[^a-zA-Z0-9._@+\/:=\-\s]/', $command)) {
            return null;
        }

        $normalizedCommand = self::normalizeCustomCommand($command);
        if ($normalizedCommand !== null && in_array($normalizedCommand, $customCommands, true)) {
            return preg_split('/\s+/', $normalizedCommand, -1, PREG_SPLIT_NO_EMPTY);
        }

        $parts = preg_split('/\s+/', $command, -1, PREG_SPLIT_NO_EMPTY);
        if (!$parts) {
            return null;
        }

        $binary = $parts[0];
        $args = array_slice($parts, 1);

        if ($binary === 'pwd' && $args === []) {
            return [$binary];
        }

        if ($binary === 'ls' && count($args) <= 1 && ($args === [] || in_array($args[0], ['-a', '-l', '-la', '-al'], true))) {
            return array_merge([$binary], $args);
        }

        if ($binary === 'npm' && self::validNpmCommand($args)) {
            return array_merge([$binary], $args);
        }

        if ($binary === 'npx' && in_array($args, [['prisma', 'db', 'push'], ['prisma', 'migrate', 'deploy']], true)) {
            return array_merge([$binary], $args);
        }

        return null;
    }

    public static function execute(array $argv, string $workingDirectory): array
    {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($argv, $descriptors, $pipes, $workingDirectory, [
            'HOME' => $workingDirectory,
            'PATH' => '/usr/local/bin:/usr/bin:/bin'
        ]);

        if (!is_resource($process)) {
            return ['exit_code' => 1, 'output' => 'Unable to start the approved command.'];
        }

        stream_set_timeout($pipes[1], 30);
        stream_set_timeout($pipes[2], 30);
        $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return ['exit_code' => $exitCode, 'output' => substr($output, 0, 20000)];
    }

    private static function validNpmCommand(array $args): bool
    {
        if ($args === [] || in_array($args[0], ['install', 'ci', 'test', 'build'], true)) {
            return count($args) === 1;
        }

        if ($args[0] !== 'run' || count($args) !== 2) {
            return false;
        }

        return preg_match('/^[a-zA-Z0-9:_-]{1,64}$/', $args[1]) === 1;
    }
}