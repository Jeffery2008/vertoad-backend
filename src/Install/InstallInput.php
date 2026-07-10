<?php

declare(strict_types=1);

namespace VertoAD\Install;

final readonly class InstallInput
{
    private function __construct(
        public string $databaseHost,
        public int $databasePort,
        public string $databaseName,
        public string $databaseUsername,
        #[\SensitiveParameter]
        public string $databasePassword,
        public string $adminEmail,
        #[\SensitiveParameter]
        public string $adminPassword,
        public string $adminDisplayName,
        public string $organizationName,
        public string $organizationSlug,
        public string $appUrl,
        public string $apiUrl,
        public string $sdkPublicBaseUrl,
        public string $adsPublicBaseUrl,
        public string $oauthRedirectUri,
    ) {
    }

    /** @param array<string, mixed> $input */
    public static function fromArray(#[\SensitiveParameter] array $input, bool $allowLocalHttp): self
    {
        $databaseHost = self::requiredString($input, 'db_host', 255);
        if (preg_match('/^[a-zA-Z0-9._:-]+$/', $databaseHost) !== 1) {
            throw new \InvalidArgumentException('Database host is invalid.');
        }

        $databasePort = filter_var($input['db_port'] ?? null, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 65535],
        ]);
        if ($databasePort === false) {
            throw new \InvalidArgumentException('Database port must be between 1 and 65535.');
        }

        $databaseName = self::requiredString($input, 'db_name', 64);
        if (preg_match('/^[a-zA-Z0-9_$-]+$/', $databaseName) !== 1) {
            throw new \InvalidArgumentException('Database name contains unsupported characters.');
        }

        $databaseUsername = self::requiredString($input, 'db_username', 128);
        if (preg_match('/[\x00-\x1F\x7F]/', $databaseUsername) === 1) {
            throw new \InvalidArgumentException('Database username contains control characters.');
        }

        $databasePassword = self::requiredString($input, 'db_password', 1024, trim: false);
        if (preg_match('/[\r\n\x00]/', $databasePassword) === 1) {
            throw new \InvalidArgumentException('Database password contains unsupported control characters.');
        }

        $adminEmail = strtolower(self::requiredString($input, 'admin_email', 255));
        if (filter_var($adminEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Administrator email is invalid.');
        }

        $adminPassword = self::requiredString($input, 'admin_password', 1024, trim: false);
        self::assertStrongAdminPassword($adminPassword, $adminEmail);
        $adminPasswordConfirmation = self::requiredString($input, 'admin_password_confirmation', 1024, trim: false);
        if (!hash_equals(hash('sha256', $adminPassword, true), hash('sha256', $adminPasswordConfirmation, true))) {
            throw new \InvalidArgumentException('Administrator password confirmation does not match.');
        }

        $organizationSlug = strtolower(self::requiredString($input, 'organization_slug', 120));
        if (preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $organizationSlug) !== 1) {
            throw new \InvalidArgumentException('Organization slug must contain lowercase letters, numbers, and single hyphens.');
        }

        $appUrl = self::requiredUrl($input, 'app_url', $allowLocalHttp);
        $apiUrl = self::requiredUrl($input, 'api_url', $allowLocalHttp);
        $oauthRedirectUri = isset($input['oauth_redirect_uri']) && trim((string) $input['oauth_redirect_uri']) !== ''
            ? self::requiredUrl($input, 'oauth_redirect_uri', $allowLocalHttp, allowQuery: true)
            : $appUrl . '/oauth/callback';

        return new self(
            $databaseHost,
            $databasePort,
            $databaseName,
            $databaseUsername,
            $databasePassword,
            $adminEmail,
            $adminPassword,
            self::requiredString($input, 'admin_display_name', 160),
            self::requiredString($input, 'organization_name', 200),
            $organizationSlug,
            $appUrl,
            $apiUrl,
            self::requiredUrl($input, 'sdk_public_base_url', $allowLocalHttp),
            self::requiredUrl($input, 'ads_public_base_url', $allowLocalHttp),
            $oauthRedirectUri,
        );
    }

    /** @return array<string, mixed> */
    public function databaseSettings(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => $this->databaseHost,
            'port' => $this->databasePort,
            'database' => $this->databaseName,
            'username' => $this->databaseUsername,
            'password' => $this->databasePassword,
            'charset' => 'utf8mb4',
        ];
    }

    /** @param array<string, mixed> $input */
    private static function requiredString(array $input, string $key, int $maxLength, bool $trim = true): string
    {
        $value = (string) ($input[$key] ?? '');
        $value = $trim ? trim($value) : $value;
        if ($value === '') {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' is required.');
        }

        if (strlen($value) > $maxLength) {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' is too long.');
        }

        return $value;
    }

    /** @param array<string, mixed> $input */
    private static function requiredUrl(array $input, string $key, bool $allowLocalHttp, bool $allowQuery = false): string
    {
        $url = rtrim(self::requiredString($input, $key, 2048), '/');
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must be a valid URL.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $localHost = $host === 'localhost'
            || $host === '::1'
            || (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($host, '127.'));
        if ($scheme !== 'https' && !($allowLocalHttp && $scheme === 'http' && $localHost)) {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must use HTTPS.');
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must not contain credentials or fragments.');
        }
        if (!$allowQuery && isset($parts['query'])) {
            throw new \InvalidArgumentException(str_replace('_', ' ', ucfirst($key)) . ' must not contain a query string.');
        }
        if ($key === 'sdk_public_base_url' && preg_match('/\.js\/?$/i', (string) ($parts['path'] ?? '')) === 1) {
            throw new \InvalidArgumentException('Sdk public base url must not include a script filename.');
        }

        return $url;
    }

    private static function assertStrongAdminPassword(#[\SensitiveParameter] string $password, string $email): void
    {
        $classes = (int) (preg_match('/[a-z]/', $password) === 1)
            + (int) (preg_match('/[A-Z]/', $password) === 1)
            + (int) (preg_match('/[0-9]/', $password) === 1)
            + (int) (preg_match('/[^a-zA-Z0-9]/', $password) === 1);
        $emailLocalPart = strtolower(strtok($email, '@') ?: '');
        if (strlen($password) < 14 || $classes < 3 || ($emailLocalPart !== '' && str_contains(strtolower($password), $emailLocalPart))) {
            throw new \InvalidArgumentException('Administrator password must be at least 14 characters, use three character classes, and not contain the email name.');
        }
    }
}
