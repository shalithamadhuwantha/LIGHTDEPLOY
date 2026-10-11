<?php
declare(strict_types=1);

namespace LightDeploy\Auth;

use RuntimeException;

class ActivationService
{
    private string $activationFile;
    private string $serverIp;

    public function __construct(string $configDir, ?string $serverIp = null)
    {
        $this->activationFile = rtrim($configDir, DIRECTORY_SEPARATOR) . '/activation.json';
        $this->serverIp = $serverIp === null ? $this->resolveServerIp() : trim($serverIp);
    }

    public function isActivated(): bool
    {
        return $this->getLicenseStatus()['activated'];
    }

    public function activate(string $key): bool
    {
        $license = $this->parseLicenseKey($key);
        if ($license === null || !$this->matchesServerIp($license['server_ip'])) {
            return false;
        }

        $expiresAt = $this->getExpiryDate($license['expires_on']);
        if ($expiresAt !== null && $expiresAt < new \DateTimeImmutable('today')) {
            return false;
        }

        if (!safeWriteJson($this->activationFile, [
            'license_key' => trim($key),
            'activated_at' => date('c'),
        ])) {
            throw new RuntimeException('Could not save the license state.');
        }

        @chmod($this->activationFile, 0600);
        return true;
    }

    public function removeLicense(): void
    {
        if (is_file($this->activationFile) && !@unlink($this->activationFile)) {
            throw new RuntimeException('Could not remove the saved license.');
        }
    }

    public function getLicenseStatus(): array
    {
        $status = [
            'activated' => false,
            'status' => 'unactivated',
            'company' => '',
            'valid_until' => '',
            'lifetime' => false,
            'server_ip' => $this->serverIp,
            'license_server_ip' => '',
            'activated_at' => '',
            'has_license' => false,
        ];

        $stored = safeReadJson($this->activationFile, []);
        $key = trim((string)($stored['license_key'] ?? ''));
        if ($key === '') {
            return $status;
        }
        $status['has_license'] = true;

        $license = $this->parseLicenseKey($key);
        if ($license === null) {
            $status['status'] = 'invalid';
            return $status;
        }

        $expiresAt = $this->getExpiryDate($license['expires_on']);
        $lifetime = $expiresAt === null;
        $expired = !$lifetime && $expiresAt < new \DateTimeImmutable('today');
        $serverMatches = $this->matchesServerIp($license['server_ip']);
        $status['status'] = !$serverMatches ? 'server_mismatch' : ($expired ? 'expired' : 'active');
        $status['activated'] = $status['status'] === 'active';
        $status['company'] = $license['company'];
        $status['valid_until'] = $lifetime ? 'Lifetime' : $license['expires_on'];
        $status['lifetime'] = $lifetime;
        $status['license_server_ip'] = $license['server_ip'];
        $status['activated_at'] = (string)($stored['activated_at'] ?? '');

        return $status;
    }

    private function parseLicenseKey(string $key): ?array
    {
        $key = trim($key);
        if ($key === '' || strlen($key) > 2048 || !preg_match('/^[A-Za-z0-9+\/_-]+={0,2}$/D', $key)) {
            return null;
        }

        $base64 = strtr($key, '-_', '+/');
        $base64 .= str_repeat('=', (4 - strlen($base64) % 4) % 4);
        $decoded = base64_decode($base64, true);
        if (!is_string($decoded)) {
            return null;
        }

        $canonicalKey = rtrim(strtr(base64_encode($decoded), '+/', '-_'), '=');
        $providedKey = rtrim(strtr($key, '+/', '-_'), '=');
        if (!hash_equals($canonicalKey, $providedKey)) {
            return null;
        }

        $parts = explode('@', $decoded);
        if (count($parts) !== 3) {
            return null;
        }

        [$company, $expiresOn, $serverIp] = array_map('trim', $parts);
        if ($company === '' || strlen($company) > 120 || preg_match('/[\x00-\x1F\x7F]/', $company)) {
            return null;
        }

        if ($expiresOn !== '0000.00.00' && $this->getExpiryDate($expiresOn) === null) {
            return null;
        }
        if (filter_var($serverIp, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        return [
            'company' => $company,
            'expires_on' => $expiresOn,
            'server_ip' => $serverIp,
        ];
    }

    private function getExpiryDate(string $expiresOn): ?\DateTimeImmutable
    {
        if ($expiresOn === '0000.00.00' || !preg_match('/^\d{4}\.\d{2}\.\d{2}$/D', $expiresOn)) {
            return null;
        }

        [$year, $month, $day] = array_map('intval', explode('.', $expiresOn));
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y.m.d', $expiresOn);
        return $date !== false && $date->format('Y.m.d') === $expiresOn ? $date : null;
    }

    private function matchesServerIp(string $licenseIp): bool
    {
        $licenseAddress = @inet_pton($licenseIp);
        $serverAddress = @inet_pton($this->serverIp);
        return is_string($licenseAddress) && is_string($serverAddress) && hash_equals($serverAddress, $licenseAddress);
    }

    private function resolveServerIp(): string
    {
        $configuredIp = getenv('LIGHTDEPLOY_LICENSE_SERVER_IP');
        if ($configuredIp !== false && $configuredIp !== '') {
            $configuredIp = trim($configuredIp);
            return filter_var($configuredIp, FILTER_VALIDATE_IP) !== false ? $configuredIp : '';
        }

        $serverAddress = trim((string)($_SERVER['SERVER_ADDR'] ?? ''));
        if ($this->isPublicIp($serverAddress)) {
            return $serverAddress;
        }

        $interfaceAddresses = $this->getInterfaceAddresses();
        foreach ($interfaceAddresses as $address) {
            if ($this->isPublicIp($address)) {
                return $address;
            }
        }

        if ($this->isUsableIp($serverAddress)) {
            return $serverAddress;
        }

        foreach ($interfaceAddresses as $address) {
            if ($this->isUsableIp($address)) {
                return $address;
            }
        }

        return '';
    }

    private function getInterfaceAddresses(): array
    {
        if (!function_exists('isFunctionAvailable') || !isFunctionAvailable('shell_exec')) {
            return [];
        }

        $output = @shell_exec('hostname -I 2>/dev/null');
        if (!is_string($output) || trim($output) === '') {
            return [];
        }

        $addresses = preg_split('/\s+/', trim($output)) ?: [];
        return array_values(array_unique(array_filter($addresses, static function (string $address): bool {
            return filter_var($address, FILTER_VALIDATE_IP) !== false;
        })));
    }

    private function isPublicIp(string $address): bool
    {
        if (!$this->isUsableIp($address)) {
            return false;
        }

        $packedAddress = @inet_pton($address);
        if (is_string($packedAddress) && strlen($packedAddress) === 4) {
            $firstOctet = ord($packedAddress[0]);
            $secondOctet = ord($packedAddress[1]);
            if ($firstOctet === 100 && $secondOctet >= 64 && $secondOctet <= 127) {
                return false;
            }
        }

        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private function isUsableIp(string $address): bool
    {
        $packedAddress = @inet_pton($address);
        if (!is_string($packedAddress)) {
            return false;
        }

        if (strlen($packedAddress) === 4) {
            return ord($packedAddress[0]) !== 127 && $packedAddress !== "\0\0\0\0";
        }

        return $packedAddress !== str_repeat("\0", 15) . "\1" && $packedAddress !== str_repeat("\0", 16);
    }
}