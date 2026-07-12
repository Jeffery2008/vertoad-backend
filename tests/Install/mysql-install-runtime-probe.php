<?php

declare(strict_types=1);

use Defuse\Crypto\Key;
use League\OAuth2\Server\CryptKey;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;

require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
require_once __DIR__ . DIRECTORY_SEPARATOR . 'MysqlInstallRehearsalSupport.php';

$options = getopt('', ['root:', 'database:', 'environment-file:', 'ssl-ca::']);
$rootPath = trim((string) ($options['root'] ?? ''));
$expectedDatabase = trim((string) ($options['database'] ?? ''));
$expectedEnvironmentFile = trim((string) ($options['environment-file'] ?? ''));
$sslCaPath = trim((string) ($options['ssl-ca'] ?? ''));
$sslCaPath = $sslCaPath === '' ? null : $sslCaPath;
$databasePassword = '';
$connection = null;
$runtimeConnection = null;
$exitCode = 1;

try {
    runtimeProbeAssert($rootPath !== '' && is_dir($rootPath), 'Runtime probe root is invalid.');
    runtimeProbeAssert($expectedDatabase !== '', 'Runtime probe expected database is missing.');
    runtimeProbeAssert($expectedEnvironmentFile !== '', 'Runtime probe expected environment file is missing.');

    $configuredEnvironmentFile = getenv('VERTOAD_ENV_FILE');
    runtimeProbeAssert(
        is_string($configuredEnvironmentFile)
            && runtimeProbeSamePath($configuredEnvironmentFile, $expectedEnvironmentFile),
        'Runtime probe did not receive the expected external environment file.',
    );

    $app = AppFactory::create($rootPath);
    $settings = require $rootPath . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'settings.php';
    $databasePassword = (string) ($settings['database']['password'] ?? '');

    runtimeProbeAssert(($settings['app']['env'] ?? null) === 'production', 'Runtime APP_ENV was polluted.');
    runtimeProbeAssert(($settings['app']['debug'] ?? null) === false, 'Runtime APP_DEBUG was polluted.');
    runtimeProbeAssert(getenv('APP_INSTALLED') === 'true', 'Runtime APP_INSTALLED was not loaded from the external environment.');
    runtimeProbeAssert(getenv('INSTALL_TOKEN') === '', 'Runtime INSTALL_TOKEN was polluted.');
    runtimeProbeAssert(getenv('DATABASE_URL') === '', 'Runtime DATABASE_URL was polluted.');
    runtimeProbeAssert(($settings['database']['database'] ?? null) === $expectedDatabase, 'Runtime DB_DATABASE was polluted.');
    runtimeProbeAssert(($settings['database']['driver'] ?? null) === 'pdo_mysql', 'Runtime DB_DRIVER was polluted.');

    $privateKeyPath = $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'private.key';
    $publicKeyPath = $rootPath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'public.key';
    runtimeProbeAssert(($settings['oauth']['private_key_path'] ?? null) === $privateKeyPath, 'Runtime OAuth private key path is invalid.');
    runtimeProbeAssert(($settings['oauth']['public_key_path'] ?? null) === $publicKeyPath, 'Runtime OAuth public key path is invalid.');
    new CryptKey($privateKeyPath, null, false);
    new CryptKey($publicKeyPath, null, false);

    $privateKey = openssl_pkey_get_private((string) file_get_contents($privateKeyPath));
    $publicKey = openssl_pkey_get_public((string) file_get_contents($publicKeyPath));
    runtimeProbeAssert($privateKey !== false && $publicKey !== false, 'Runtime OAuth key pair is invalid.');
    $derivedPublic = openssl_pkey_get_details($privateKey);
    $storedPublic = openssl_pkey_get_details($publicKey);
    runtimeProbeAssert(
        is_array($derivedPublic)
            && is_array($storedPublic)
            && hash_equals(
                hash('sha256', (string) ($derivedPublic['key'] ?? '')),
                hash('sha256', (string) ($storedPublic['key'] ?? '')),
            ),
        'Runtime OAuth public and private keys do not match.',
    );
    Key::loadFromAsciiSafeString((string) ($settings['app']['key'] ?? ''));

    $container = $app->getContainer();
    runtimeProbeAssert($container !== null, 'Runtime application container is unavailable.');
    $runtimeConnection = $container->get(Doctrine\DBAL\Connection::class);
    runtimeProbeAssert($runtimeConnection instanceof Doctrine\DBAL\Connection, 'Runtime application database connection is invalid.');
    $runtimeConnectedDatabase = (string) $runtimeConnection->fetchOne('SELECT DATABASE()');
    runtimeProbeAssert($runtimeConnectedDatabase === $expectedDatabase, 'Runtime application connected to an unexpected database.');
    $runtimeTlsCipher = runtimeProbeTlsCipher($runtimeConnection);
    if (!mysqlInstallRehearsalIsLoopback((string) ($settings['database']['host'] ?? ''))) {
        runtimeProbeAssert($runtimeTlsCipher !== '', 'Runtime application database connection is not using verified TLS.');
    }

    $database = [
        'host' => (string) ($settings['database']['host'] ?? ''),
        'port' => (int) ($settings['database']['port'] ?? 0),
        'username' => (string) ($settings['database']['username'] ?? ''),
        'password' => $databasePassword,
    ];
    $connection = mysqlInstallRehearsalConnect(
        $database,
        mysqlInstallRehearsalDriverOptions($sslCaPath, 5),
        $expectedDatabase,
    );
    $connectedDatabase = (string) $connection->fetchOne('SELECT DATABASE()');
    runtimeProbeAssert($connectedDatabase === $expectedDatabase, 'Runtime connected to an unexpected database.');
    $tlsCipher = runtimeProbeTlsCipher($connection);
    if (!mysqlInstallRehearsalIsLoopback($database['host'])) {
        runtimeProbeAssert($sslCaPath !== null && $tlsCipher !== '', 'Remote runtime database probe is not using verified TLS.');
    }

    $disabled = $app->handle((new ServerRequestFactory())->createServerRequest(
        'PUT',
        'https://api.rehearsal.vertoad.invalid/install',
        ['REMOTE_ADDR' => '198.51.100.77'],
    ));
    runtimeProbeAssert($disabled->getStatusCode() === 410, 'Installed runtime did not permanently disable /install.');

    $publicKeyFingerprint = hash('sha256', (string) ($storedPublic['key'] ?? ''));
    fwrite(STDOUT, json_encode([
        'schema' => 'vertoad.mysql-install-runtime-probe.v1',
        'status' => 'passed',
        'environment_file_loaded' => true,
        'app_env' => $settings['app']['env'],
        'app_installed' => true,
        'app_debug' => $settings['app']['debug'],
        'database_name' => $settings['database']['database'],
        'connected_database' => $connectedDatabase,
        'runtime_connected_database' => $runtimeConnectedDatabase,
        'database_url_empty' => true,
        'install_token_empty' => true,
        'app_key_valid' => true,
        'oauth_private_key_valid' => true,
        'oauth_public_key_valid' => true,
        'oauth_key_pair_matches' => true,
        'oauth_public_key_sha256' => $publicKeyFingerprint,
        'installed_route_status' => $disabled->getStatusCode(),
        'tls_cipher' => $tlsCipher === '' ? null : $tlsCipher,
        'runtime_tls_cipher' => $runtimeTlsCipher === '' ? null : $runtimeTlsCipher,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    $exitCode = 0;
} catch (Throwable $exception) {
    $message = mysqlInstallRehearsalRedact($exception::class . ': ' . $exception->getMessage(), [$databasePassword]);
    fwrite(STDERR, 'MySQL installation runtime probe failed: ' . $message . PHP_EOL);
} finally {
    if ($runtimeConnection instanceof Doctrine\DBAL\Connection) {
        $runtimeConnection->close();
    }
    if ($connection instanceof Doctrine\DBAL\Connection) {
        $connection->close();
    }
}

exit($exitCode);

function runtimeProbeTlsCipher(Doctrine\DBAL\Connection $connection): string
{
    $row = $connection->fetchAssociative("SHOW SESSION STATUS LIKE 'Ssl_cipher'");

    return is_array($row) ? trim((string) ($row['Value'] ?? $row['value'] ?? '')) : '';
}

function runtimeProbeSamePath(string $left, string $right): bool
{
    $left = str_replace('\\', '/', realpath($left) ?: $left);
    $right = str_replace('\\', '/', realpath($right) ?: $right);
    if (DIRECTORY_SEPARATOR === '\\') {
        $left = strtolower($left);
        $right = strtolower($right);
    }

    return rtrim($left, '/') === rtrim($right, '/');
}

function runtimeProbeAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
