<?php

declare(strict_types=1);

namespace VertoAD\Install;

use Doctrine\DBAL\Connection;
use VertoAD\Infrastructure\Database\ConnectionFactory;

final readonly class InstallerService implements InstallerInterface
{
    private \Closure $connectionFactory;

    public function __construct(
        private string $appEnvironment,
        private InstallState $state,
        private InstallFilesystem $filesystem,
        private MigrationRunnerInterface $migrations,
        private BootstrapSeeder $seeder,
        private InstallSecretGenerator $secrets,
        ?callable $connectionFactory = null,
    ) {
        $this->connectionFactory = $connectionFactory === null
            ? static fn (array $settings): Connection => (new ConnectionFactory())->create($settings)
            : \Closure::fromCallable($connectionFactory);
    }

    public function install(
        InstallInput $input,
        bool $localInstallation,
        ?string $clientIp,
        ?string $userAgent,
        string $requestId,
    ): array {
        return $this->filesystem->synchronized(function () use ($input, $localInstallation, $clientIp, $userAgent, $requestId): array {
            if ($this->state->hasPermanentLock() || $this->state->isInstalled()) {
                throw new InstallHttpException(410, 'already_installed', 'VertoAD has already been installed.');
            }

            $databaseSettings = $input->databaseSettings();
            $secrets = $this->secrets->generate();
            $keyPair = $this->secrets->generateOAuthKeyPair();
            $this->migrations->migrate($databaseSettings);

            $connection = ($this->connectionFactory)($databaseSettings);
            try {
                $connection->beginTransaction();
                $result = $this->seeder->seed($connection, $input, $secrets, $clientIp, $userAgent, $requestId);
                $environment = $this->environmentValues(
                    $input,
                    $secrets,
                    $this->filesystem->oauthEnvironmentKeyPaths(),
                    $localInstallation,
                );
                $this->filesystem->commitInstallation(
                    $environment,
                    $keyPair,
                    [
                        'format_version' => 1,
                        'state' => 'installed',
                        'installation_id' => $result['installation_id'],
                        'installed_at' => gmdate(DATE_ATOM),
                        'admin_user_id' => $result['admin_user_id'],
                        'organization_id' => $result['organization_id'],
                    ],
                    static function () use ($connection): void {
                        $connection->commit();
                    },
                );
            } catch (\Throwable $exception) {
                if ($connection->isTransactionActive()) {
                    try {
                        $connection->rollBack();
                    } catch (\Throwable $rollbackException) {
                        throw new \RuntimeException('Installation database rollback failed.', 0, $exception);
                    }
                }

                throw $exception;
            } finally {
                $connection->close();
            }

            return $result;
        });
    }

    /**
     * @param array{installation_id: string, app_key: string, oauth_encryption_key: string, oauth_client_id: string, cron_api_token: string, webhook_signing_secret: string} $secrets
     * @param array{private_key_path: string, public_key_path: string} $keyPaths
     * @return array<string, scalar|null>
     */
    private function environmentValues(
        InstallInput $input,
        #[\SensitiveParameter] array $secrets,
        array $keyPaths,
        bool $localInstallation,
    ): array
    {
        $environment = $this->installedEnvironment($localInstallation);

        return [
            'APP_ENV' => $environment,
            'APP_DEBUG' => false,
            'APP_INSTALLED' => true,
            'APP_INSTALLATION_ID' => $secrets['installation_id'],
            'APP_KEY' => $secrets['app_key'],
            'APP_URL' => $input->appUrl,
            'SDK_PUBLIC_BASE_URL' => $input->sdkPublicBaseUrl,
            'ADS_PUBLIC_BASE_URL' => $input->adsPublicBaseUrl,
            'DB_DRIVER' => 'pdo_mysql',
            'DB_HOST' => $input->databaseHost,
            'DB_PORT' => $input->databasePort,
            'DB_DATABASE' => $input->databaseName,
            'DB_USERNAME' => $input->databaseUsername,
            'DB_PASSWORD' => $input->databasePassword,
            'DB_CHARSET' => 'utf8mb4',
            'DATABASE_URL' => '',
            'PHINX_ENVIRONMENT' => in_array($environment, ['prod', 'production'], true) ? 'production' : 'development',
            'OAUTH_PRIVATE_KEY_PATH' => $keyPaths['private_key_path'],
            'OAUTH_PUBLIC_KEY_PATH' => $keyPaths['public_key_path'],
            'OAUTH_ENCRYPTION_KEY' => $secrets['oauth_encryption_key'],
            'OAUTH_AUTHORIZATION_URL' => $input->appUrl . '/oauth/authorize',
            'OAUTH_TOKEN_URL' => $input->apiUrl . '/api/v1/oauth/token',
            'CRON_API_TOKEN' => $secrets['cron_api_token'],
            'WEBHOOK_SIGNING_SECRET' => $secrets['webhook_signing_secret'],
            'INSTALL_TOKEN' => '',
        ];
    }

    private function installedEnvironment(bool $localInstallation): string
    {
        $environment = strtolower(trim($this->appEnvironment));
        if ($localInstallation) {
            return match ($environment) {
                '', 'dev', 'development', 'local' => 'local',
                'test', 'testing' => 'testing',
                'staging' => 'staging',
                'prod', 'production' => 'production',
                default => throw new InstallHttpException(422, 'invalid_app_environment', 'APP_ENV is not supported by the installer.'),
            };
        }

        if ($environment === '') {
            return 'production';
        }
        if (in_array($environment, ['dev', 'development', 'local', 'test', 'testing'], true)) {
            throw new InstallHttpException(
                422,
                'unsafe_remote_environment',
                'Remote installation requires APP_ENV=production or APP_ENV=staging.',
            );
        }

        return match ($environment) {
            'staging' => 'staging',
            'prod', 'production' => 'production',
            default => throw new InstallHttpException(422, 'invalid_app_environment', 'APP_ENV is not supported by the installer.'),
        };
    }
}
