<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;

class DailyDeploymentLimit
{
    private const DEFAULT_SETTINGS = [
        'enabled' => true,
        'daily_limit' => 20,
        'behavior' => 'block',
        'authorized_users' => [],
    ];

    private string $settingsPath;
    private string $counterPath;
    private DateTimeZone $timezone;

    public function __construct(string $settingsPath, string $counterPath, string $timezone)
    {
        $this->settingsPath = $settingsPath;
        $this->counterPath = $counterPath;
        $this->timezone = new DateTimeZone($timezone);
    }

    public function getSettings(): array
    {
        if (!file_exists($this->settingsPath)) {
            return self::DEFAULT_SETTINGS;
        }

        $contents = file_get_contents($this->settingsPath);
        if ($contents === false) {
            throw new RuntimeException('Unable to read deployment limit settings.');
        }

        $settings = json_decode($contents, true);
        if (!is_array($settings)) {
            throw new RuntimeException('Deployment limit settings contain invalid JSON.');
        }

        try {
            return $this->validateSettings(array_merge(self::DEFAULT_SETTINGS, $settings));
        } catch (InvalidArgumentException $exception) {
            throw new RuntimeException('Deployment limit settings contain invalid values.', 0, $exception);
        }
    }

    public function saveSettings(array $settings): array
    {
        $validated = $this->validateSettings($settings);
        if (!safeWriteJson($this->settingsPath, $validated)) {
            throw new RuntimeException('Unable to save deployment limit settings.');
        }
        return $validated;
    }

    public function getStatus(?DateTimeImmutable $now = null): array
    {
        $settings = $this->getSettings();
        $now = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $today = $now->format('Y-m-d');
        $counter = $this->withLockedCounter(static function (array $stored) use ($today): array {
            if (($stored['date'] ?? '') !== $today) {
                return ['date' => $today, 'count' => 0];
            }
            return $stored;
        });

        return $this->buildStatus($settings, (int)$counter['count'], $now);
    }

    public function reserve(string $username, bool $continueAfterLimit = false, ?DateTimeImmutable $now = null): array
    {
        $settings = $this->getSettings();
        $now = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $today = $now->format('Y-m-d');

        if (!$settings['enabled']) {
            return ['allowed' => true, 'counted' => false, 'override_used' => false, 'status' => $this->getStatus($now)];
        }

        $authorized = in_array(
            strtolower($username),
            array_map('strtolower', $settings['authorized_users']),
            true
        );

        return $this->withLockedCounter(function (array $stored) use (
            $settings,
            $continueAfterLimit,
            $authorized,
            $now,
            $today
        ): array {
            $count = ($stored['date'] ?? '') === $today ? (int)$stored['count'] : 0;
            $atLimit = $count >= $settings['daily_limit'];
            $confirmationRequired = $atLimit && $settings['behavior'] === 'warn' && $authorized && !$continueAfterLimit;

            if ($atLimit && ($settings['behavior'] === 'block' || !$authorized || !$continueAfterLimit)) {
                return [
                    'allowed' => false,
                    'counted' => false,
                    'override_used' => false,
                    'confirmation_required' => $confirmationRequired,
                    'authorized_to_override' => $authorized,
                    'status' => $this->buildStatus($settings, $count, $now),
                ];
            }

            $count++;
            return [
                'allowed' => true,
                'counted' => true,
                'override_used' => $atLimit && $settings['behavior'] === 'warn' && $authorized && $continueAfterLimit,
                'status' => $this->buildStatus($settings, $count, $now),
                'counter' => ['date' => $today, 'count' => $count],
            ];
        });
    }

    public function release(?DateTimeImmutable $now = null): void
    {
        $now = ($now ?? new DateTimeImmutable('now', $this->timezone))->setTimezone($this->timezone);
        $today = $now->format('Y-m-d');
        $this->withLockedCounter(static function (array $stored) use ($today): array {
            if (($stored['date'] ?? '') !== $today) {
                return $stored;
            }
            $stored['count'] = max(0, (int)$stored['count'] - 1);
            return $stored;
        }, true);
    }

    private function validateSettings(array $settings): array
    {
        $enabled = $settings['enabled'] ?? null;
        $limit = $settings['daily_limit'] ?? null;
        $behavior = $settings['behavior'] ?? null;
        $authorizedUsers = $settings['authorized_users'] ?? null;

        if (!is_bool($enabled)) {
            throw new InvalidArgumentException('Deployment limit enabled must be a boolean.');
        }
        if (!is_int($limit) || $limit < 1 || $limit > 1000000) {
            throw new InvalidArgumentException('Daily deployment limit must be between 1 and 1,000,000.');
        }
        if (!in_array($behavior, ['block', 'warn'], true)) {
            throw new InvalidArgumentException('Deployment limit behavior must be block or warn.');
        }
        if (!is_array($authorizedUsers) || count($authorizedUsers) > 100) {
            throw new InvalidArgumentException('Authorized deployment override users must be a list of up to 100 usernames.');
        }

        $users = [];
        foreach ($authorizedUsers as $username) {
            if (!is_string($username) || !preg_match('/^[a-zA-Z0-9_.-]{3,32}$/', $username)) {
                throw new InvalidArgumentException('An authorized deployment override username is invalid.');
            }
            $users[strtolower($username)] = $username;
        }

        return [
            'enabled' => $enabled,
            'daily_limit' => $limit,
            'behavior' => $behavior,
            'authorized_users' => array_values($users),
        ];
    }

    private function buildStatus(array $settings, int $count, DateTimeImmutable $now): array
    {
        return [
            'enabled' => $settings['enabled'],
            'daily_limit' => $settings['daily_limit'],
            'behavior' => $settings['behavior'],
            'count' => $count,
            'remaining' => $settings['enabled'] ? max(0, $settings['daily_limit'] - $count) : null,
            'reset_at' => $now->modify('tomorrow')->setTime(0, 0)->format(DATE_ATOM),
            'timezone' => $this->timezone->getName(),
        ];
    }

    private function withLockedCounter(callable $callback, bool $write = false): array
    {
        $directory = dirname($this->counterPath);
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            throw new RuntimeException('Unable to create deployment counter storage.');
        }

        $file = fopen($this->counterPath, 'c+');
        if ($file === false) {
            throw new RuntimeException('Unable to open deployment counter storage.');
        }

        try {
            if (!flock($file, LOCK_EX)) {
                throw new RuntimeException('Unable to lock deployment counter storage.');
            }

            rewind($file);
            $contents = stream_get_contents($file);
            if ($contents === false) {
                throw new RuntimeException('Unable to read deployment counter storage.');
            }
            if (trim($contents) === '') {
                $stored = ['date' => '', 'count' => 0];
            } else {
                $stored = json_decode($contents, true);
                if (!is_array($stored) || !isset($stored['date'], $stored['count']) || !is_numeric($stored['count']) || (int)$stored['count'] < 0) {
                    throw new RuntimeException('Deployment counter storage contains invalid data.');
                }
            }

            $result = $callback($stored);
            if ($write || isset($result['counter'])) {
                $counter = $result['counter'] ?? $result;
                $encoded = json_encode($counter, JSON_UNESCAPED_SLASHES);
                if ($encoded === false || !ftruncate($file, 0) || !rewind($file)) {
                    throw new RuntimeException('Unable to prepare deployment counter storage.');
                }
                $written = fwrite($file, $encoded);
                if ($written !== strlen($encoded) || !fflush($file)) {
                    throw new RuntimeException('Unable to persist deployment counter storage.');
                }
                unset($result['counter']);
            }

            return $result;
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }
}
