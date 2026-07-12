<?php

declare(strict_types=1);

namespace VertoAD\Install;

use Defuse\Crypto\Key;
use OpenSSLAsymmetricKey;

final readonly class InstallSecretGenerator
{
    public function __construct(
        private int $rsaBits = 3072,
        private ?string $opensslConfigPath = null,
    ) {
    }

    /** @return array{installation_id: string, app_key: string, oauth_encryption_key: string, oauth_client_id: string, cron_api_token: string, webhook_signing_secret: string} */
    public function generate(): array
    {
        return [
            'installation_id' => bin2hex(random_bytes(16)),
            'app_key' => Key::createNewRandomKey()->saveToAsciiSafeString(),
            'oauth_encryption_key' => $this->token('', 32),
            'oauth_client_id' => $this->token('voc_', 24),
            'cron_api_token' => $this->token('vcron_', 48),
            'webhook_signing_secret' => $this->token('vwhsec_', 48),
        ];
    }

    /** @return array{private_key: string, public_key: string} */
    public function generateOAuthKeyPair(): array
    {
        $options = [
            'private_key_bits' => $this->rsaBits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];
        $configPath = $this->opensslConfigPath ?? $this->detectOpenSslConfigPath();
        if ($configPath !== null) {
            $options['config'] = $configPath;
        }

        $key = openssl_pkey_new($options);
        if (!$key instanceof OpenSSLAsymmetricKey) {
            throw new \RuntimeException('Unable to generate the OAuth RSA key pair.');
        }

        $privateKey = '';
        if (!openssl_pkey_export($key, $privateKey, null, $options)) {
            throw new \RuntimeException('Unable to export the OAuth private key.');
        }

        $details = openssl_pkey_get_details($key);
        if (!is_array($details) || !is_string($details['key'] ?? null)) {
            throw new \RuntimeException('Unable to export the OAuth public key.');
        }

        return ['private_key' => $privateKey, 'public_key' => $details['key']];
    }

    private function token(string $prefix, int $bytes): string
    {
        return $prefix . rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    private function detectOpenSslConfigPath(): ?string
    {
        $candidates = [
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf',
            (openssl_get_cert_locations()['default_default_cert_area'] ?? '') . DIRECTORY_SEPARATOR . 'openssl.cnf',
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '' && is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
