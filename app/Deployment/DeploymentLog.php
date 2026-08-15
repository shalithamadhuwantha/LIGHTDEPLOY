<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

class DeploymentLog
{
    private string $logsDir;

    public function __construct(string $logsDir)
    {
        $this->logsDir = $logsDir . '/deployments';
        ensureDirExists($this->logsDir);
    }

    public function getLogFilePath(string $deploymentId): string
    {
        return $this->logsDir . '/' . $deploymentId . '.log';
    }

    public function getMetaFilePath(string $deploymentId): string
    {
        return $this->logsDir . '/' . $deploymentId . '.json';
    }

    public function saveDeployment(array $meta, string $outputLogContent): void
    {
        $metaFile = $this->getMetaFilePath($meta['deployment_id']);
        $logFile = $this->getLogFilePath($meta['deployment_id']);

        safeWriteJson($metaFile, $meta);
        @file_put_contents($logFile, $outputLogContent, LOCK_EX);
    }

    public function getLog(string $deploymentId): ?array
    {
        $metaFile = $this->getMetaFilePath($deploymentId);
        $logFile = $this->getLogFilePath($deploymentId);

        if (!file_exists($metaFile)) {
            return null;
        }

        $meta = safeReadJson($metaFile, []);
        $output = file_exists($logFile) ? file_get_contents($logFile) : '';

        return [
            'meta' => $meta,
            'output' => $output
        ];
    }

    public function getHistory(int $limit = 50): array
    {
        $files = glob($this->logsDir . '/*.json');
        if (!$files) {
            return [];
        }

        $history = [];
        foreach ($files as $metaFile) {
            $meta = safeReadJson($metaFile, []);
            if (!empty($meta['deployment_id'])) {
                $history[] = $meta;
            }
        }

        // Sort descending by start time
        usort($history, function ($a, $b) {
            return ($b['start_time_ts'] ?? 0) <=> ($a['start_time_ts'] ?? 0);
        });

        return array_slice($history, 0, $limit);
    }
}
