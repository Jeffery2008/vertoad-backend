<?php

declare(strict_types=1);

use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Dotenv\Dotenv;
use VertoAD\Install\BootstrapConfigCatalog;
use VertoAD\Install\BootstrapSeeder;
use VertoAD\Install\InstallFilesystem;
use VertoAD\Install\InstallInput;
use VertoAD\Install\InstallerService;
use VertoAD\Install\InstallSecretGenerator;
use VertoAD\Install\InstallState;
use VertoAD\Service\PermissionInventory;

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MysqlInstallRehearsalSupport.php';

exit(mysqlInstallRehearsalMain());

function mysqlInstallRehearsalMain(): int
{
    $sourceRoot = dirname(__DIR__, 2);
    $runId = bin2hex(random_bytes(16));
    $workspaceRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-rehearsal-' . $runId;
    $temporaryRoot = $workspaceRoot . DIRECTORY_SEPARATOR . 'app';
    $externalEnvironmentPath = $workspaceRoot . DIRECTORY_SEPARATOR . 'secrets' . DIRECTORY_SEPARATOR . 'vertoad.env';
    $databaseName = 'vertoad_install_rehearsal_' . $runId;
    $startedAt = microtime(true);
    $processEnvironment = rehearsalEnvironment();
    $databasePassword = (string) ($processEnvironment['MYSQL_REHEARSAL_PASSWORD'] ?? '');
    $requestedHost = trim((string) ($processEnvironment['MYSQL_REHEARSAL_HOST'] ?? '127.0.0.1'));
    $requestedLoopback = $requestedHost === '' ? null : mysqlInstallRehearsalIsLoopback($requestedHost);
    $requestedSslCa = trim((string) ($processEnvironment['MYSQL_REHEARSAL_SSL_CA'] ?? ''));
    $stage = 'process_isolation';
    $configuration = null;
    $admin = null;
    $application = null;
    $databaseCreated = false;
    $workspaceOwned = false;
    $installationCompleted = false;
    $processEnvironmentIsolated = false;
    $failure = null;
    $cleanupFailures = [];
    $serverVersion = null;
    $serverMajorVersion = null;
    $tlsCipher = null;
    $installationEvidence = [
        'migration_count' => null,
        'permission_count' => null,
        'config_count' => null,
        'installed_route_status' => null,
        'runtime_probe' => null,
    ];
    $cleanup = [
        'database_was_created' => false,
        'database_drop_attempted' => false,
        'database_absence_verified' => false,
        'workspace_root_absent' => false,
        'temporary_root_absent' => false,
        'external_environment_absent' => false,
        'external_environment_temporary_files_absent' => false,
    ];
    $baseExitCode = 1;

    try {
        mysqlInstallRehearsalSanitizeCurrentEnvironment();
        rehearsalAssert(rehearsalProcessEnvironmentIsIsolated(), 'The rehearsal process retained caller application or secret variables.');
        $processEnvironmentIsolated = true;

        $stage = 'configuration';
        $configuration = rehearsalConfiguration($processEnvironment);
        $databasePassword = $configuration['password'];
        rehearsalAssert(!rehearsalPathIsWithin($workspaceRoot, $sourceRoot), 'Temporary rehearsal workspace must be outside the Git checkout.');
        rehearsalAssert(!rehearsalPathIsWithin($temporaryRoot, $sourceRoot), 'Temporary rehearsal root must be outside the Git checkout.');
        rehearsalAssert(!rehearsalPathIsWithin($externalEnvironmentPath, $sourceRoot), 'External installer environment must be outside the Git checkout.');

        $stage = 'workspace_reserve';
        rehearsalReserveWorkspace($workspaceRoot);
        $workspaceOwned = true;

        $driverOptions = mysqlInstallRehearsalDriverOptions(
            $configuration['ssl_ca_path'],
            $configuration['connect_timeout_seconds'],
        );
        $stage = 'database_connect';
        $admin = mysqlInstallRehearsalConnect($configuration, $driverOptions, 'mysql');
        $serverVersion = (string) $admin->fetchOne('SELECT VERSION()');
        preg_match('/^(\d+)\./', $serverVersion, $versionMatches);
        $serverMajorVersion = isset($versionMatches[1]) ? (int) $versionMatches[1] : null;
        rehearsalAssert($serverMajorVersion === 8, 'The installation rehearsal requires MySQL 8.');
        $tlsCipher = rehearsalTlsCipher($admin);
        if (!$configuration['loopback']) {
            rehearsalAssert($tlsCipher !== '', 'The remote MySQL connection is not using verified TLS.');
        }

        $stage = 'database_create';
        $admin->executeStatement(sprintf(
            'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $admin->quoteSingleIdentifier($databaseName),
        ));
        $databaseCreated = true;
        $cleanup['database_was_created'] = true;
        rehearsalInjectFault($configuration['fault_stage'], 'after_database_create');

        $stage = 'installer_prepare';
        rehearsalPrepareRoot($sourceRoot, $temporaryRoot);
        $input = InstallInput::fromArray([
            'db_host' => $configuration['host'],
            'db_port' => $configuration['port'],
            'db_name' => $databaseName,
            'db_username' => $configuration['username'],
            'db_password' => $configuration['password'],
            'admin_email' => 'installer-rehearsal@example.invalid',
            'admin_password' => 'Rehearsal-Admin-2026!',
            'admin_password_confirmation' => 'Rehearsal-Admin-2026!',
            'admin_display_name' => 'Installer Rehearsal Admin',
            'organization_name' => 'VertoAD Rehearsal',
            'organization_slug' => 'vertoad-rehearsal',
            'app_url' => 'https://app.rehearsal.vertoad.invalid',
            'api_url' => 'https://api.rehearsal.vertoad.invalid',
            'sdk_public_base_url' => 'https://sdk.rehearsal.vertoad.invalid',
            'ads_public_base_url' => 'https://ads.rehearsal.vertoad.invalid',
            'oauth_redirect_uri' => 'https://app.rehearsal.vertoad.invalid/oauth/callback',
        ], false);
        $service = new InstallerService(
            '',
            new InstallState($temporaryRoot),
            new InstallFilesystem($temporaryRoot, environmentPath: $externalEnvironmentPath),
            new MysqlInstallRehearsalMigrationRunner($temporaryRoot, $configuration['ssl_ca_path']),
            new BootstrapSeeder(),
            new InstallSecretGenerator(),
            static function (#[SensitiveParameter] array $settings) use ($driverOptions): Connection {
                return mysqlInstallRehearsalConnect(
                    [
                        'host' => (string) ($settings['host'] ?? ''),
                        'port' => (int) ($settings['port'] ?? 0),
                        'username' => (string) ($settings['username'] ?? ''),
                        'password' => (string) ($settings['password'] ?? ''),
                    ],
                    $driverOptions,
                    (string) ($settings['database'] ?? ''),
                );
            },
        );

        $stage = 'installer_execute';
        $requestId = 'install-rehearsal-' . $runId;
        $result = $service->install(
            $input,
            false,
            '198.51.100.77',
            'VertoAD MySQL Installer Rehearsal/1.0',
            $requestId,
        );
        rehearsalInjectFault($configuration['fault_stage'], 'after_installer_execute');

        $stage = 'installer_evidence';
        $environment = Dotenv::createArrayBacked(
            dirname($externalEnvironmentPath),
            basename($externalEnvironmentPath),
        )->load();
        rehearsalVerifyExternalEnvironment($environment, $databaseName, $configuration);

        $privateKeyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'private.key';
        $publicKeyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'public.key';
        rehearsalAssert(is_file($privateKeyPath) && openssl_pkey_get_private((string) file_get_contents($privateKeyPath)) !== false, 'OAuth private key is invalid.');
        rehearsalAssert(is_file($publicKeyPath) && openssl_pkey_get_public((string) file_get_contents($publicKeyPath)) !== false, 'OAuth public key is invalid.');
        $lock = json_decode(
            (string) file_get_contents($temporaryRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'install.lock'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        rehearsalAssert(($lock['state'] ?? null) === 'installed', 'Permanent install.lock is invalid.');
        rehearsalAssert(($lock['installation_id'] ?? null) === $result['installation_id'], 'install.lock installation ID does not match the database result.');

        $application = mysqlInstallRehearsalConnect($configuration, $driverOptions, $databaseName);
        rehearsalVerifyDatabase($application, $result, $requestId);

        $stage = 'runtime_probe';
        $runtimeProbe = rehearsalRunRuntimeProbe(
            $sourceRoot,
            $temporaryRoot,
            $externalEnvironmentPath,
            $databaseName,
            $configuration['ssl_ca_path'],
            $databasePassword,
        );
        rehearsalVerifyRuntimeProbe($runtimeProbe, $databaseName);

        $installationEvidence = [
            'migration_count' => (int) $application->fetchOne('SELECT COUNT(*) FROM phinxlog'),
            'permission_count' => count((new PermissionInventory())->all()),
            'config_count' => count(BootstrapConfigCatalog::all()),
            'installed_route_status' => $runtimeProbe['installed_route_status'],
            'runtime_probe' => $runtimeProbe,
        ];
        $installationCompleted = true;
        $baseExitCode = 0;
    } catch (MysqlInstallRehearsalConfigurationException $exception) {
        $baseExitCode = 64;
        $failure = [
            'stage' => 'configuration',
            'type' => $exception::class,
            'message' => mysqlInstallRehearsalRedact($exception->getMessage(), [$databasePassword]),
        ];
    } catch (Throwable $exception) {
        $baseExitCode = 1;
        $failure = [
            'stage' => $stage,
            'type' => $exception::class,
            'message' => mysqlInstallRehearsalRedact($exception->getMessage(), [$databasePassword]),
        ];
    } finally {
        if ($application instanceof Connection) {
            $application->close();
        }

        if ($admin instanceof Connection) {
            if ($databaseCreated && preg_match('/^vertoad_install_rehearsal_[a-f0-9]{32}$/D', $databaseName) === 1) {
                $cleanup['database_drop_attempted'] = true;
                try {
                    $admin->executeStatement('DROP DATABASE IF EXISTS ' . $admin->quoteSingleIdentifier($databaseName));
                    $cleanup['database_absence_verified'] = (int) $admin->fetchOne(
                        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
                        [$databaseName],
                    ) === 0;
                    if (!$cleanup['database_absence_verified']) {
                        throw new RuntimeException('Temporary rehearsal database still exists after cleanup.');
                    }
                } catch (Throwable $cleanupException) {
                    $cleanupFailures[] = 'database: ' . mysqlInstallRehearsalRedact(
                        $cleanupException->getMessage(),
                        [$databasePassword],
                    );
                }
            }
            $admin->close();
        }

        if ($workspaceOwned) {
            try {
                rehearsalRemoveRoot($workspaceRoot);
            } catch (Throwable $cleanupException) {
                $cleanupFailures[] = 'workspace_root: ' . $cleanupException->getMessage();
            }
        }
        $cleanup['workspace_root_absent'] = !file_exists($workspaceRoot) && !is_link($workspaceRoot);
        $cleanup['temporary_root_absent'] = !file_exists($temporaryRoot) && !is_link($temporaryRoot);
        $cleanup['external_environment_absent'] = !file_exists($externalEnvironmentPath);
        $environmentTemporaryFiles = glob(
            dirname($externalEnvironmentPath) . DIRECTORY_SEPARATOR . '.' . basename($externalEnvironmentPath) . '.*.tmp',
        ) ?: [];
        $cleanup['external_environment_temporary_files_absent'] = $environmentTemporaryFiles === [];
    }

    $cleanupComplete = $cleanup['database_was_created']
        && $cleanup['database_drop_attempted']
        && $cleanup['database_absence_verified']
        && $cleanup['workspace_root_absent']
        && $cleanup['temporary_root_absent']
        && $cleanup['external_environment_absent']
        && $cleanup['external_environment_temporary_files_absent'];
    $passed = $installationCompleted && $cleanupComplete && $cleanupFailures === [] && $baseExitCode === 0;
    if ($installationCompleted && !$passed && $failure === null) {
        $failure = [
            'stage' => 'cleanup',
            'type' => RuntimeException::class,
            'message' => 'The rehearsal completed but cleanup evidence is incomplete.',
        ];
    }
    $exitCode = $passed ? 0 : ($baseExitCode === 64 ? 64 : 1);

    $evidence = [
        'schema' => 'vertoad.mysql-install-rehearsal.v1',
        'status' => $passed ? 'passed' : 'failed',
        'exit_code' => $exitCode,
        'run_id' => $runId,
        'process_environment_isolated' => $processEnvironmentIsolated,
        'mysql' => [
            'version' => $serverVersion,
            'major_version' => $serverMajorVersion,
            'host_scope' => is_array($configuration)
                ? ($configuration['loopback'] ? 'loopback' : 'remote')
                : ($requestedLoopback === null ? null : ($requestedLoopback ? 'loopback' : 'remote')),
            'tls_required' => is_array($configuration) ? !$configuration['loopback'] : ($requestedLoopback === null ? null : !$requestedLoopback),
            'tls_ca_configured' => is_array($configuration) ? $configuration['ssl_ca_path'] !== null : $requestedSslCa !== '',
            'tls_cipher' => $tlsCipher === '' ? null : $tlsCipher,
        ],
        'artifacts' => [
            'database_name' => $databaseName,
            'workspace_root' => $workspaceRoot,
            'temporary_root' => $temporaryRoot,
            'external_environment_file' => $externalEnvironmentPath,
        ],
        'installation' => $installationEvidence,
        'cleanup' => $cleanup,
        'failure' => $failure,
        'cleanup_failures' => $cleanupFailures,
        'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
    ];

    fwrite(STDOUT, json_encode(
        $evidence,
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE,
    ) . PHP_EOL);
    if (!$passed && is_array($failure)) {
        fwrite(STDERR, sprintf(
            'MySQL installation rehearsal failed at %s: %s%s',
            $failure['stage'],
            $failure['message'],
            PHP_EOL,
        ));
    }
    foreach ($cleanupFailures as $cleanupFailure) {
        fwrite(STDERR, 'MySQL installation rehearsal cleanup failed: ' . $cleanupFailure . PHP_EOL);
    }

    return $exitCode;
}

final class MysqlInstallRehearsalConfigurationException extends RuntimeException
{
}

/**
 * @param array<string, string> $environment
 * @return array{host: string, port: int, username: string, password: string, loopback: bool, ssl_ca_path: ?string, connect_timeout_seconds: int, fault_stage: string}
 */
function rehearsalConfiguration(#[SensitiveParameter] array $environment): array
{
    if (($environment['VERTOAD_MYSQL_INSTALL_REHEARSAL'] ?? '') !== '1') {
        throw new MysqlInstallRehearsalConfigurationException(
            'Set VERTOAD_MYSQL_INSTALL_REHEARSAL=1 to run the real MySQL installation rehearsal.',
        );
    }

    $host = trim((string) ($environment['MYSQL_REHEARSAL_HOST'] ?? '127.0.0.1'));
    if ($host === '') {
        throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_HOST must not be empty.');
    }
    if (strlen($host) > 255 || preg_match('/^[a-zA-Z0-9._:-]+$/D', $host) !== 1) {
        throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_HOST is invalid.');
    }
    $port = filter_var($environment['MYSQL_REHEARSAL_PORT'] ?? 3306, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 1, 'max_range' => 65535],
    ]);
    if ($port === false) {
        throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_PORT must be between 1 and 65535.');
    }

    $username = trim((string) ($environment['MYSQL_REHEARSAL_USERNAME'] ?? ''));
    if ($username === '') {
        throw new MysqlInstallRehearsalConfigurationException(
            'MYSQL_REHEARSAL_USERNAME must be set explicitly; the rehearsal never selects root implicitly.',
        );
    }
    if (strlen($username) > 128 || preg_match('/[\x00-\x1F\x7F]/', $username) === 1) {
        throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_USERNAME is invalid.');
    }
    $password = (string) ($environment['MYSQL_REHEARSAL_PASSWORD'] ?? '');
    if ($password === '') {
        throw new MysqlInstallRehearsalConfigurationException(
            'MYSQL_REHEARSAL_PASSWORD must be set for the real installation rehearsal.',
        );
    }
    if (strlen($password) > 1024 || preg_match('/[\r\n\x00]/', $password) === 1) {
        throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_PASSWORD contains unsupported characters.');
    }

    $connectTimeout = filter_var(
        $environment['MYSQL_REHEARSAL_CONNECT_TIMEOUT_SECONDS'] ?? 5,
        FILTER_VALIDATE_INT,
        ['options' => ['min_range' => 1, 'max_range' => 60]],
    );
    if ($connectTimeout === false) {
        throw new MysqlInstallRehearsalConfigurationException(
            'MYSQL_REHEARSAL_CONNECT_TIMEOUT_SECONDS must be between 1 and 60.',
        );
    }

    $loopback = mysqlInstallRehearsalIsLoopback($host);
    if (!$loopback && strcasecmp($username, 'root') === 0) {
        throw new MysqlInstallRehearsalConfigurationException(
            'Remote MySQL installation rehearsals must use an explicit non-root account.',
        );
    }
    $sslCaPath = trim((string) ($environment['MYSQL_REHEARSAL_SSL_CA'] ?? ''));
    if (!$loopback && $sslCaPath === '') {
        throw new MysqlInstallRehearsalConfigurationException(
            'MYSQL_REHEARSAL_SSL_CA is required for non-loopback MySQL and server certificate verification is mandatory.',
        );
    }
    if ($sslCaPath !== '') {
        if (!rehearsalPathIsAbsolute($sslCaPath)) {
            throw new MysqlInstallRehearsalConfigurationException('MYSQL_REHEARSAL_SSL_CA must be an absolute path.');
        }
        $resolvedCaPath = realpath($sslCaPath);
        if ($resolvedCaPath === false || !is_file($resolvedCaPath) || !is_readable($resolvedCaPath)) {
            throw new MysqlInstallRehearsalConfigurationException(
                'MYSQL_REHEARSAL_SSL_CA must point to a readable CA certificate file.',
            );
        }
        $sslCaPath = $resolvedCaPath;
    }

    $faultStage = trim((string) ($environment['MYSQL_REHEARSAL_FAULT_STAGE'] ?? ''));
    if (!in_array($faultStage, ['', 'after_database_create', 'after_installer_execute'], true)) {
        throw new MysqlInstallRehearsalConfigurationException(
            'MYSQL_REHEARSAL_FAULT_STAGE must be empty, after_database_create, or after_installer_execute.',
        );
    }

    return [
        'host' => $host,
        'port' => (int) $port,
        'username' => $username,
        'password' => $password,
        'loopback' => $loopback,
        'ssl_ca_path' => $sslCaPath === '' ? null : $sslCaPath,
        'connect_timeout_seconds' => (int) $connectTimeout,
        'fault_stage' => $faultStage,
    ];
}

/** @return array<string, string> */
function rehearsalEnvironment(): array
{
    $environment = [];
    foreach ([
        'VERTOAD_MYSQL_INSTALL_REHEARSAL',
        'MYSQL_REHEARSAL_HOST',
        'MYSQL_REHEARSAL_PORT',
        'MYSQL_REHEARSAL_USERNAME',
        'MYSQL_REHEARSAL_PASSWORD',
        'MYSQL_REHEARSAL_SSL_CA',
        'MYSQL_REHEARSAL_CONNECT_TIMEOUT_SECONDS',
        'MYSQL_REHEARSAL_FAULT_STAGE',
    ] as $name) {
        $value = getenv($name);
        if (is_string($value)) {
            $environment[$name] = $value;
        }
    }

    return $environment;
}

function rehearsalProcessEnvironmentIsIsolated(): bool
{
    foreach ([
        'APP_ENV',
        'APP_DEBUG',
        'APP_INSTALLED',
        'DATABASE_URL',
        'DB_PASSWORD',
        'INSTALL_TOKEN',
        'MYSQL_REHEARSAL_PASSWORD',
        'OAUTH_ENCRYPTION_KEY',
        'VERTOAD_MYSQL_INSTALL_REHEARSAL',
    ] as $name) {
        if (getenv($name) !== false) {
            return false;
        }
    }

    return true;
}

function rehearsalInjectFault(string $configuredStage, string $currentStage): void
{
    if ($configuredStage === $currentStage) {
        throw new RuntimeException('Injected installer rehearsal failure at ' . $currentStage . '.');
    }
}

/**
 * @param array<string, string> $environment
 * @param array{host: string, port: int, username: string, password: string} $configuration
 */
function rehearsalVerifyExternalEnvironment(
    #[SensitiveParameter] array $environment,
    string $databaseName,
    #[SensitiveParameter] array $configuration,
): void {
    rehearsalAssert(($environment['APP_ENV'] ?? null) === 'production', 'Installer did not persist APP_ENV=production.');
    rehearsalAssert(($environment['APP_DEBUG'] ?? null) === 'false', 'Installer did not disable APP_DEBUG.');
    rehearsalAssert(($environment['APP_INSTALLED'] ?? null) === 'true', 'Installer did not persist APP_INSTALLED=true.');
    rehearsalAssert(($environment['INSTALL_TOKEN'] ?? null) === '', 'Installer did not clear INSTALL_TOKEN.');
    rehearsalAssert(($environment['DATABASE_URL'] ?? null) === '', 'Installer did not clear DATABASE_URL.');
    rehearsalAssert(($environment['DB_DRIVER'] ?? null) === 'pdo_mysql', 'Installer did not persist DB_DRIVER=pdo_mysql.');
    rehearsalAssert(($environment['DB_HOST'] ?? null) === $configuration['host'], 'Installer persisted an unexpected DB_HOST.');
    rehearsalAssert((int) ($environment['DB_PORT'] ?? 0) === $configuration['port'], 'Installer persisted an unexpected DB_PORT.');
    rehearsalAssert(($environment['DB_DATABASE'] ?? null) === $databaseName, 'Installer persisted an unexpected DB_DATABASE.');
    rehearsalAssert(($environment['DB_USERNAME'] ?? null) === $configuration['username'], 'Installer persisted an unexpected DB_USERNAME.');
    rehearsalAssert(($environment['DB_PASSWORD'] ?? null) === $configuration['password'], 'Installer persisted an unexpected DB_PASSWORD.');
    rehearsalAssert(($environment['OAUTH_PRIVATE_KEY_PATH'] ?? null) === 'storage/oauth/private.key', 'OAuth private key path is not project-relative.');
    rehearsalAssert(($environment['OAUTH_PUBLIC_KEY_PATH'] ?? null) === 'storage/oauth/public.key', 'OAuth public key path is not project-relative.');
    rehearsalAssert(!array_key_exists('OAUTH_CLIENT_SECRET', $environment), 'One-time OAuth client secret leaked into the external environment.');
    Key::loadFromAsciiSafeString((string) ($environment['APP_KEY'] ?? ''));
    rehearsalAssert(strlen((string) ($environment['OAUTH_ENCRYPTION_KEY'] ?? '')) >= 43, 'OAuth encryption key is too short.');
    rehearsalAssert(str_starts_with((string) ($environment['CRON_API_TOKEN'] ?? ''), 'vcron_'), 'Cron secret was not generated.');
    rehearsalAssert(str_starts_with((string) ($environment['WEBHOOK_SIGNING_SECRET'] ?? ''), 'vwhsec_'), 'Webhook secret was not generated.');
}

/** @return array<string, mixed> */
function rehearsalRunRuntimeProbe(
    string $sourceRoot,
    string $temporaryRoot,
    string $externalEnvironmentPath,
    string $databaseName,
    ?string $sslCaPath,
    #[SensitiveParameter] string $databasePassword,
): array {
    $pollutedEnvironment = [
        'APP_ENV' => 'polluted-parent-environment',
        'APP_DEBUG' => 'true',
        'APP_INSTALLED' => 'false',
        'DATABASE_URL' => 'mysql://polluted.invalid/polluted',
        'INSTALL_TOKEN' => 'polluted-install-token',
        'DB_DRIVER' => 'pdo_sqlite',
        'DB_HOST' => 'polluted.invalid',
        'DB_PORT' => '1',
        'DB_DATABASE' => 'polluted_database',
        'DB_USERNAME' => 'polluted_user',
        'DB_PASSWORD' => 'polluted_password',
        'DB_CHARSET' => 'latin1',
        'OAUTH_PRIVATE_KEY_PATH' => 'polluted/private.key',
        'OAUTH_PUBLIC_KEY_PATH' => 'polluted/public.key',
        'OAUTH_ENCRYPTION_KEY' => 'polluted-oauth-key',
    ];
    foreach ($pollutedEnvironment as $name => $value) {
        putenv($name . '=' . $value);
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    $command = [
        PHP_BINARY,
        __DIR__ . DIRECTORY_SEPARATOR . 'mysql-install-runtime-probe.php',
        '--root=' . $temporaryRoot,
        '--database=' . $databaseName,
        '--environment-file=' . $externalEnvironmentPath,
    ];
    if ($sslCaPath !== null) {
        $command[] = '--ssl-ca=' . $sslCaPath;
    }
    try {
        $childEnvironment = mysqlInstallRehearsalCleanProcessEnvironment([
            'VERTOAD_ENV_FILE' => $externalEnvironmentPath,
        ]);
    } finally {
        foreach (array_keys($pollutedEnvironment) as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
    }
    $process = mysqlInstallRehearsalRunProcess($command, $sourceRoot, $childEnvironment, 90);
    if ($process['exit_code'] !== 0 || $process['timed_out']) {
        $diagnostic = trim($process['stderr']) !== '' ? trim($process['stderr']) : trim($process['stdout']);
        throw new RuntimeException(sprintf(
            'Runtime probe exited with code %d: %s',
            $process['exit_code'],
            mysqlInstallRehearsalRedact($diagnostic, [$databasePassword]),
        ));
    }

    try {
        $evidence = json_decode(trim($process['stdout']), true, flags: JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('Runtime probe did not emit valid JSON evidence.', 0, $exception);
    }
    rehearsalAssert(is_array($evidence), 'Runtime probe evidence must be a JSON object.');

    return $evidence;
}

/** @param array<string, mixed> $evidence */
function rehearsalVerifyRuntimeProbe(array $evidence, string $databaseName): void
{
    rehearsalAssert(($evidence['schema'] ?? null) === 'vertoad.mysql-install-runtime-probe.v1', 'Runtime probe evidence schema is invalid.');
    rehearsalAssert(($evidence['status'] ?? null) === 'passed', 'Runtime probe did not pass.');
    rehearsalAssert(($evidence['environment_file_loaded'] ?? null) === true, 'Runtime probe did not load the external environment.');
    rehearsalAssert(($evidence['app_env'] ?? null) === 'production', 'Runtime probe APP_ENV evidence is invalid.');
    rehearsalAssert(($evidence['app_installed'] ?? null) === true, 'Runtime probe APP_INSTALLED evidence is invalid.');
    rehearsalAssert(($evidence['app_debug'] ?? null) === false, 'Runtime probe debug evidence is invalid.');
    rehearsalAssert(($evidence['database_name'] ?? null) === $databaseName, 'Runtime probe database setting is invalid.');
    rehearsalAssert(($evidence['connected_database'] ?? null) === $databaseName, 'Runtime probe connected database is invalid.');
    rehearsalAssert(($evidence['runtime_connected_database'] ?? null) === $databaseName, 'Runtime application connected database is invalid.');
    rehearsalAssert(($evidence['database_url_empty'] ?? null) === true, 'Runtime probe DATABASE_URL evidence is invalid.');
    rehearsalAssert(($evidence['install_token_empty'] ?? null) === true, 'Runtime probe INSTALL_TOKEN evidence is invalid.');
    rehearsalAssert(($evidence['app_key_valid'] ?? null) === true, 'Runtime probe APP_KEY evidence is invalid.');
    rehearsalAssert(($evidence['oauth_private_key_valid'] ?? null) === true, 'Runtime probe OAuth private key evidence is invalid.');
    rehearsalAssert(($evidence['oauth_public_key_valid'] ?? null) === true, 'Runtime probe OAuth public key evidence is invalid.');
    rehearsalAssert(($evidence['oauth_key_pair_matches'] ?? null) === true, 'Runtime probe OAuth key pair evidence is invalid.');
    rehearsalAssert(preg_match('/^[a-f0-9]{64}$/D', (string) ($evidence['oauth_public_key_sha256'] ?? '')) === 1, 'Runtime probe OAuth fingerprint is invalid.');
    rehearsalAssert(($evidence['installed_route_status'] ?? null) === 410, 'Runtime probe installed route status is invalid.');
}

function rehearsalVerifyDatabase(Connection $connection, array $result, string $requestId): void
{
    foreach (['users', 'organizations', 'organization_members', 'roles', 'user_roles', 'oauth_clients', 'app_installations'] as $table) {
        rehearsalAssert((int) $connection->fetchOne('SELECT COUNT(*) FROM ' . $table) === 1, $table . ' bootstrap count is invalid.');
    }

    $expectedPermissions = array_column((new PermissionInventory())->all(), 'code');
    sort($expectedPermissions);
    $actualPermissions = array_map('strval', $connection->fetchFirstColumn('SELECT slug FROM permissions ORDER BY slug'));
    rehearsalAssert($actualPermissions === $expectedPermissions, 'Database permissions do not match PermissionInventory.');
    rehearsalAssert((int) $connection->fetchOne('SELECT COUNT(*) FROM role_permissions') === count($expectedPermissions), 'Super admin role does not own every permission.');
    rehearsalAssert((int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_scopes') === count($expectedPermissions), 'OAuth scopes do not match PermissionInventory.');
    rehearsalAssert((int) $connection->fetchOne('SELECT COUNT(*) FROM oauth_client_scopes') === count($expectedPermissions), 'Initial OAuth client does not own every scope.');

    $expectedConfigKeys = array_keys(BootstrapConfigCatalog::all());
    sort($expectedConfigKeys);
    $actualConfigKeys = array_map('strval', $connection->fetchFirstColumn('SELECT config_key FROM system_config_versions ORDER BY config_key'));
    rehearsalAssert($actualConfigKeys === $expectedConfigKeys, 'Bootstrap system config catalog is incomplete.');
    $geo = json_decode((string) $connection->fetchOne("SELECT value_json FROM system_config_versions WHERE config_key = 'serving.geo_provider'"), true, flags: JSON_THROW_ON_ERROR);
    rehearsalAssert(($geo['enabled'] ?? false) === true && ($geo['include_builtins'] ?? false) === true, 'serving.geo_provider bootstrap value is invalid.');
    rehearsalAssert((int) $connection->fetchOne("SELECT COUNT(*) FROM revenue_share_rules WHERE scope = 'global' AND share_ratio_bps = 7000 AND status = 'active'") === 1, 'Global revenue share was not seeded.');

    $user = $connection->fetchAssociative('SELECT email, password_hash, status, email_verified_at FROM users');
    rehearsalAssert(is_array($user) && $user['email'] === 'installer-rehearsal@example.invalid', 'Initial administrator identity is invalid.');
    rehearsalAssert(password_verify('Rehearsal-Admin-2026!', (string) $user['password_hash']), 'Initial administrator password hash is invalid.');
    rehearsalAssert($user['status'] === 'active' && $user['email_verified_at'] !== null, 'Initial administrator status is invalid.');
    rehearsalAssert((int) $connection->fetchOne("SELECT COUNT(*) FROM roles WHERE slug = 'super-admin' AND organization_id IS NULL AND is_system = 1") === 1, 'Global super admin role is invalid.');
    rehearsalAssert((int) $connection->fetchOne('SELECT COUNT(*) FROM user_roles WHERE organization_id IS NULL') === 1, 'Initial administrator was not assigned the global role.');

    $oauth = $connection->fetchAssociative('SELECT client_identifier, secret_hash, grant_types_json, is_confidential FROM oauth_clients');
    rehearsalAssert(is_array($oauth) && $oauth['client_identifier'] === $result['oauth_client_id'], 'Initial OAuth client identifier is invalid.');
    rehearsalAssert($oauth['secret_hash'] === null && (int) $oauth['is_confidential'] === 0, 'Initial OAuth client must be public and secretless.');
    rehearsalAssert(json_decode((string) $oauth['grant_types_json'], true, flags: JSON_THROW_ON_ERROR) === ['authorization_code', 'refresh_token'], 'Initial OAuth client grant types are invalid.');
    rehearsalAssert((string) $connection->fetchOne('SELECT request_id FROM app_installations') === $requestId, 'Installation request ID was not persisted.');
    rehearsalAssert((int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'installation.completed' AND request_id = ?", [$requestId]) === 1, 'Installation audit record is missing.');
}

function rehearsalReserveWorkspace(string $workspaceRoot): void
{
    $temporaryBase = realpath(sys_get_temp_dir());
    rehearsalAssert($temporaryBase !== false, 'Unable to resolve the temporary directory.');
    rehearsalAssert(rehearsalSamePath(dirname($workspaceRoot), $temporaryBase), 'Temporary rehearsal workspace must be a direct child of the temporary directory.');
    rehearsalAssert(preg_match('/^vertoad-install-rehearsal-[a-f0-9]{32}$/D', basename($workspaceRoot)) === 1, 'Temporary rehearsal workspace name is invalid.');
    rehearsalAssert(!file_exists($workspaceRoot) && !is_link($workspaceRoot), 'Temporary rehearsal workspace already exists.');
    rehearsalAssert(mkdir($workspaceRoot, 0700), 'Unable to reserve the temporary rehearsal workspace.');
}

function rehearsalPrepareRoot(string $sourceRoot, string $temporaryRoot): void
{
    rehearsalAssert(!file_exists($temporaryRoot) && !is_link($temporaryRoot), 'Temporary rehearsal root already exists.');
    rehearsalAssert(mkdir($temporaryRoot, 0700), 'Unable to create the temporary rehearsal root.');
    foreach (['config', 'db/migrations', 'db/seeds'] as $directory) {
        $path = $temporaryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create rehearsal directory ' . $directory . '.');
        }
    }
    foreach (['settings.php', 'routes.php'] as $file) {
        rehearsalAssert(copy($sourceRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file, $temporaryRoot . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . $file), 'Unable to copy config/' . $file . '.');
    }
    rehearsalAssert(copy($sourceRoot . DIRECTORY_SEPARATOR . '.env.example', $temporaryRoot . DIRECTORY_SEPARATOR . '.env.example'), 'Unable to copy .env.example.');
    foreach (glob($sourceRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . '*.php') ?: [] as $migration) {
        rehearsalAssert(copy($migration, $temporaryRoot . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations' . DIRECTORY_SEPARATOR . basename($migration)), 'Unable to copy migration ' . basename($migration) . '.');
    }
}

function rehearsalTlsCipher(Connection $connection): string
{
    $row = $connection->fetchAssociative("SHOW SESSION STATUS LIKE 'Ssl_cipher'");

    return is_array($row) ? trim((string) ($row['Value'] ?? $row['value'] ?? '')) : '';
}

function rehearsalRemoveRoot(string $path): void
{
    if (!file_exists($path)) {
        return;
    }
    rehearsalAssert(is_dir($path) && !is_link($path), 'Temporary rehearsal root is not a directory.');
    $temporaryBase = realpath(sys_get_temp_dir());
    $resolved = realpath($path);
    rehearsalAssert($temporaryBase !== false && $resolved !== false, 'Unable to resolve temporary cleanup path.');
    rehearsalAssert(rehearsalSamePath(dirname($resolved), $temporaryBase), 'Refusing to remove a path outside the temporary directory.');
    rehearsalAssert(preg_match('/^vertoad-install-rehearsal-[a-f0-9]{32}$/D', basename($resolved)) === 1, 'Refusing to remove an unexpected rehearsal root.');

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        rehearsalAssert(!$item->isLink(), 'Refusing to follow a link during rehearsal cleanup.');
        $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rehearsalAssert($removed, 'Unable to remove rehearsal path ' . $item->getPathname() . '.');
    }
    rehearsalAssert(rmdir($resolved), 'Unable to remove rehearsal root.');
}

function rehearsalPathIsAbsolute(string $path): bool
{
    return str_starts_with($path, '/')
        || preg_match('/^[a-zA-Z]:[\\\\\/]/D', $path) === 1
        || preg_match('~^(?:\\\\\\\\|//)[^\\\\/]+[\\\\/][^\\\\/]+(?:[\\\\/]|$)~D', $path) === 1;
}

function rehearsalPathIsWithin(string $path, string $root): bool
{
    $path = str_replace('\\', '/', realpath($path) ?: $path);
    $root = str_replace('\\', '/', realpath($root) ?: $root);
    if (DIRECTORY_SEPARATOR === '\\') {
        $path = strtolower($path);
        $root = strtolower($root);
    }
    $path = rtrim($path, '/');
    $root = rtrim($root, '/');

    return $path === $root || str_starts_with($path, $root . '/');
}

function rehearsalSamePath(string $left, string $right): bool
{
    $left = str_replace('\\', '/', realpath($left) ?: $left);
    $right = str_replace('\\', '/', realpath($right) ?: $right);
    if (DIRECTORY_SEPARATOR === '\\') {
        $left = strtolower($left);
        $right = strtolower($right);
    }

    return rtrim($left, '/') === rtrim($right, '/');
}

function rehearsalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
