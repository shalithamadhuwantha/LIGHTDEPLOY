<?php
declare(strict_types=1);

namespace LightDeploy\Deployment;

class HealthChecker
{
    private int $timeout;
    private int $retries;
    private int $delay;

    public function __construct(int $timeout = 10, int $retries = 3, int $delay = 2)
    {
        $this->timeout = $timeout;
        $this->retries = $retries;
        $this->delay = $delay;
    }

    public function check(string $url): array
    {
        $attempts = 0;
        $lastCode = 0;
        $lastError = '';

        while ($attempts < $this->retries) {
            $attempts++;
            $res = $this->makeRequest($url);
            $lastCode = $res['code'];

            if ($res['success'] && $res['code'] >= 200 && $res['code'] < 300) {
                return [
                    'success' => true,
                    'http_code' => $res['code'],
                    'attempts' => $attempts,
                    'message' => "Health check passed with HTTP {$res['code']} on attempt {$attempts}."
                ];
            }

            $lastError = $res['error'] ?? "HTTP response status code {$res['code']}";

            if ($attempts < $this->retries) {
                sleep($this->delay);
            }
        }

        return [
            'success' => false,
            'http_code' => $lastCode,
            'attempts' => $attempts,
            'message' => "Health check failed after {$attempts} attempts. Last status: {$lastCode}. Error: {$lastError}"
        ];
    }

    private function makeRequest(string $url): array
    {
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER => false,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => $this->timeout,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_USERAGENT => 'LightDeploy-HealthChecker/1.0',
            ]);

            $output = curl_exec($ch);
            $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $errno = curl_errno($ch);
            $error = curl_error($ch);
            curl_close($ch);

            if ($errno !== 0) {
                return ['success' => false, 'code' => 0, 'error' => "cURL error ($errno): $error"];
            }

            return ['success' => true, 'code' => $code, 'error' => null];
        }

        // Fallback to stream context
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => "User-Agent: LightDeploy-HealthChecker/1.0\r\n",
                'ignore_errors' => true
            ]
        ]);

        $fp = @fopen($url, 'r', false, $context);
        if (!$fp) {
            return ['success' => false, 'code' => 0, 'error' => 'Unable to open connection'];
        }

        $meta = stream_get_meta_data($fp);
        fclose($fp);

        $code = 0;
        if (!empty($meta['wrapper_data'])) {
            foreach ($meta['wrapper_data'] as $header) {
                if (preg_match('#HTTP/\d\.\d\s+(\d{3})#i', $header, $matches)) {
                    $code = (int)$matches[1];
                    break;
                }
            }
        }

        return ['success' => true, 'code' => $code, 'error' => null];
    }
}
