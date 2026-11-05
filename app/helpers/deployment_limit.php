<?php
declare(strict_types=1);

if (!function_exists('reserveDailyDeployment')) {
    function reserveDailyDeployment(
        \LightDeploy\Deployment\DailyDeploymentLimit $limit,
        string $username,
        bool $continueAfterLimit,
        \LightDeploy\Security\SecurityLogger $logger,
        string $siteId,
        string $operation
    ): array {
        try {
            $reservation = $limit->reserve($username, $continueAfterLimit);
        } catch (\RuntimeException $exception) {
            $logger->log('DEPLOYMENT_LIMIT_CHECK_FAILED', ['reason' => $exception->getMessage()], $username);
            jsonError('DEPLOYMENT_LIMIT_ERROR', "Unable to verify the daily deployment limit. No {$operation} was started.", 500);
        }

        if (!$reservation['allowed']) {
            $confirmationRequired = !empty($reservation['confirmation_required']);
            $logger->log('DAILY_DEPLOYMENT_LIMIT_REACHED', [
                'site_id' => $siteId,
                'operation' => $operation,
                'count' => $reservation['status']['count'],
                'limit' => $reservation['status']['daily_limit'],
                'authorized_to_override' => $reservation['authorized_to_override'] ?? false,
            ], $username);
            jsonError(
                $confirmationRequired ? 'DAILY_DEPLOYMENT_LIMIT_CONFIRMATION_REQUIRED' : 'DAILY_DEPLOYMENT_LIMIT_REACHED',
                $confirmationRequired
                    ? 'The daily deployment limit has been reached. Confirm to continue.'
                    : 'The daily deployment limit has been reached.',
                $confirmationRequired ? 409 : 429,
                $reservation['status'] + [
                    'authorized_to_override' => $reservation['authorized_to_override'] ?? false,
                ]
            );
        }

        return $reservation;
    }
}

if (!function_exists('releaseDailyDeploymentReservation')) {
    function releaseDailyDeploymentReservation(
        \LightDeploy\Deployment\DailyDeploymentLimit $limit,
        array $reservation,
        \LightDeploy\Security\SecurityLogger $logger,
        string $username,
        string $operation
    ): void {
        if (empty($reservation['counted'])) {
            return;
        }

        try {
            $limit->release();
        } catch (\RuntimeException $exception) {
            $logger->log('DEPLOYMENT_LIMIT_RELEASE_FAILED', ['reason' => $exception->getMessage()], $username);
            jsonError('DEPLOYMENT_LIMIT_ERROR', ucfirst($operation) . ' failed and its daily count could not be released.', 500);
        }
    }
}

if (!function_exists('logDailyDeploymentOverride')) {
    function logDailyDeploymentOverride(
        array $reservation,
        \LightDeploy\Security\SecurityLogger $logger,
        string $username,
        string $siteId,
        string $operation
    ): void {
        if (empty($reservation['override_used'])) {
            return;
        }

        $logger->log('DAILY_DEPLOYMENT_LIMIT_OVERRIDE', [
            'site_id' => $siteId,
            'operation' => $operation,
            'count' => $reservation['status']['count'],
            'limit' => $reservation['status']['daily_limit'],
        ], $username);
    }
}
