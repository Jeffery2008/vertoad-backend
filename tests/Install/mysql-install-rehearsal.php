<?php

declare(strict_types=1);

use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Dotenv\Dotenv;
use League\OAuth2\Server\CryptKey;
use Slim\Psr7\Factory\ServerRequestFactory;
use VertoAD\AppFactory;
use VertoAD\Install\BootstrapConfigCatalog;
use VertoAD\Install\BootstrapSeeder;
use VertoAD\Install\InstallFilesystem;
use VertoAD\Install\InstallInput;
use VertoAD\Install\InstallerService;
use VertoAD\Install\InstallSecretGenerator;
use VertoAD\Install\InstallState;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Service\OAuthClientSecretHasher;
use VertoAD\Service\PermissionInventory;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$sourceRoot = dirname(__DIR__, 2);
$temporaryRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-install-rehearsal-' . bin2hex(random_bytes(8));
$databaseName = 'vertoad_install_rehearsal_' . bin2hex(random_bytes(8));
$host = getenv('MYSQL_REHEARSAL_HOST') ?: '127.0.0.1';
$port = (int) (getenv('MYSQL_REHEARSAL_PORT') ?: 3306);
$username = getenv('MYSQL_REHEARSAL_USERNAME') ?: 'root';
$password = getenv('MYSQL_REHEARSAL_PASSWORD');
$password = $password === false ? '' : $password;
$admin = null;
$application = null;
$databaseCreated = false;
$exitCode = 0;
$startedAt = microtime(true);

try {
    rehearsalAssert($port >= 1 && $port <= 65535, 'MySQL rehearsal port is invalid.');
    rehearsalAssert($password !== '', 'MYSQL_REHEARSAL_PASSWORD must be set for the real installation rehearsal.');
    rehearsalPrepareRoot($sourceRoot, $temporaryRoot);

    $admin = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => $host,
        'port' => $port,
        'dbname' => 'mysql',
        'user' => $username,
        'password' => $password,
        'charset' => 'utf8mb4',
    ]);
    $serverVersion = (string) $admin->fetchOne('SELECT VERSION()');
    $admin->executeStatement(sprintf(
        'CREATE DATABASE %s CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        $admin->quoteIdentifier($databaseName),
    ));
    $databaseCreated = true;

    $input = InstallInput::fromArray([
        'db_host' => $host,
        'db_port' => $port,
        'db_name' => $databaseName,
        'db_username' => $username,
        'db_password' => $password,
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
        new InstallFilesystem($temporaryRoot),
        new PhinxMigrationRunner($temporaryRoot),
        new BootstrapSeeder(),
        new InstallSecretGenerator(),
    );
    $requestId = 'install-rehearsal-' . bin2hex(random_bytes(8));
    $result = $service->install($input, false, '198.51.100.77', 'VertoAD MySQL Installer Rehearsal/1.0', $requestId);

    $environment = Dotenv::createArrayBacked($temporaryRoot)->load();
    rehearsalAssert(($environment['APP_ENV'] ?? null) === 'production', 'Remote installation did not derive APP_ENV=production.');
    rehearsalAssert(($environment['APP_DEBUG'] ?? null) === 'false', 'Installer did not disable APP_DEBUG.');
    rehearsalAssert(($environment['APP_INSTALLED'] ?? null) === 'true', 'Installer did not persist APP_INSTALLED=true.');
    rehearsalAssert(($environment['INSTALL_TOKEN'] ?? null) === '', 'Installer did not clear INSTALL_TOKEN.');
    rehearsalAssert(($environment['OAUTH_PRIVATE_KEY_PATH'] ?? null) === 'storage/oauth/private.key', 'OAuth private key path is not project-relative.');
    rehearsalAssert(($environment['OAUTH_PUBLIC_KEY_PATH'] ?? null) === 'storage/oauth/public.key', 'OAuth public key path is not project-relative.');
    rehearsalAssert(!array_key_exists('OAUTH_CLIENT_SECRET', $environment), 'One-time OAuth client secret leaked into .env.');
    Key::loadFromAsciiSafeString((string) ($environment['APP_KEY'] ?? ''));
    rehearsalAssert(strlen((string) ($environment['OAUTH_ENCRYPTION_KEY'] ?? '')) >= 43, 'OAuth encryption key is too short.');
    rehearsalAssert(str_starts_with((string) ($environment['CRON_API_TOKEN'] ?? ''), 'vcron_'), 'Cron secret was not generated.');
    rehearsalAssert(str_starts_with((string) ($environment['WEBHOOK_SIGNING_SECRET'] ?? ''), 'vwhsec_'), 'Webhook secret was not generated.');

    $privateKeyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'private.key';
    $publicKeyPath = $temporaryRoot . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'oauth' . DIRECTORY_SEPARATOR . 'public.key';
    rehearsalAssert(is_file($privateKeyPath) && openssl_pkey_get_private((string) file_get_contents($privateKeyPath)) !== false, 'OAuth private key is invalid.');
    rehearsalAssert(is_file($publicKeyPath) && openssl_pkey_get_public((string) file_get_contents($publicKeyPath)) !== false, 'OAuth public key is invalid.');
    $lock = json_decode((string) file_get_contents($temporaryRoot . '/storage/install.lock'), true, flags: JSON_THROW_ON_ERROR);
    rehearsalAssert(($lock['state'] ?? null) === 'installed', 'Permanent install.lock is invalid.');
    rehearsalAssert(($lock['installation_id'] ?? null) === $result['installation_id'], 'install.lock installation ID does not match the database result.');

    $application = DriverManager::getConnection([
        'driver' => 'pdo_mysql',
        'host' => $host,
        'port' => $port,
        'dbname' => $databaseName,
        'user' => $username,
        'password' => $password,
        'charset' => 'utf8mb4',
    ]);
    rehearsalVerifyDatabase($application, $result, $requestId);

    rehearsalClearRuntimeEnvironment();
    $app = AppFactory::create($temporaryRoot);
    $settings = require $temporaryRoot . '/config/settings.php';
    rehearsalAssert($settings['oauth']['private_key_path'] === $privateKeyPath, 'Runtime did not resolve the relative OAuth private key path.');
    rehearsalAssert($settings['oauth']['public_key_path'] === $publicKeyPath, 'Runtime did not resolve the relative OAuth public key path.');
    new CryptKey($settings['oauth']['private_key_path'], null, false);
    new CryptKey($settings['oauth']['public_key_path'], null, false);
    $disabled = $app->handle((new ServerRequestFactory())->createServerRequest(
        'PUT',
        'https://api.rehearsal.vertoad.invalid/install',
        ['REMOTE_ADDR' => '198.51.100.77'],
    ));
    rehearsalAssert($disabled->getStatusCode() === 410, 'Installed application did not permanently disable /install.');

    fwrite(STDOUT, json_encode([
        'status' => 'passed',
        'mysql_version' => $serverVersion,
        'migration_count' => (int) $application->fetchOne('SELECT COUNT(*) FROM phinxlog'),
        'permission_count' => count((new PermissionInventory())->all()),
        'config_count' => count(BootstrapConfigCatalog::all()),
        'installed_route_status' => $disabled->getStatusCode(),
        'elapsed_seconds' => round(microtime(true) - $startedAt, 3),
    ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $exception) {
    $exitCode = 1;
    fwrite(STDERR, 'MySQL installation rehearsal failed: ' . $exception::class . ': ' . $exception->getMessage() . PHP_EOL);
} finally {
    if ($application instanceof Connection) {
        $application->close();
    }
    if ($admin instanceof Connection) {
        if ($databaseCreated && str_starts_with($databaseName, 'vertoad_install_rehearsal_')) {
            try {
                $admin->executeStatement('DROP DATABASE IF EXISTS ' . $admin->quoteIdentifier($databaseName));
            } catch (Throwable $cleanupException) {
                $exitCode = 1;
                fwrite(STDERR, 'Database cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
            }
        }
        $admin->close();
    }
    try {
        rehearsalRemoveRoot($temporaryRoot);
    } catch (Throwable $cleanupException) {
        $exitCode = 1;
        fwrite(STDERR, 'Temporary root cleanup failed: ' . $cleanupException->getMessage() . PHP_EOL);
    }
}

exit($exitCode);

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

    $oauth = $connection->fetchAssociative('SELECT client_identifier, secret_hash FROM oauth_clients');
    rehearsalAssert(is_array($oauth) && $oauth['client_identifier'] === $result['oauth_client_id'], 'Initial OAuth client identifier is invalid.');
    rehearsalAssert((new OAuthClientSecretHasher())->verify($result['oauth_client_secret'], (string) $oauth['secret_hash']), 'Initial OAuth client secret hash is invalid.');
    rehearsalAssert((string) $connection->fetchOne('SELECT request_id FROM app_installations') === $requestId, 'Installation request ID was not persisted.');
    rehearsalAssert((int) $connection->fetchOne("SELECT COUNT(*) FROM audit_logs WHERE action = 'installation.completed' AND request_id = ?", [$requestId]) === 1, 'Installation audit record is missing.');
}

function rehearsalPrepareRoot(string $sourceRoot, string $temporaryRoot): void
{
    foreach (['config', 'db/migrations', 'db/seeds'] as $directory) {
        $path = $temporaryRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $directory);
        if (!mkdir($path, 0700, true) && !is_dir($path)) {
            throw new RuntimeException('Unable to create rehearsal directory ' . $directory . '.');
        }
    }
    foreach (['settings.php', 'routes.php'] as $file) {
        rehearsalAssert(copy($sourceRoot . '/config/' . $file, $temporaryRoot . '/config/' . $file), 'Unable to copy config/' . $file . '.');
    }
    rehearsalAssert(copy($sourceRoot . '/.env.example', $temporaryRoot . '/.env.example'), 'Unable to copy .env.example.');
    foreach (glob($sourceRoot . '/db/migrations/*.php') ?: [] as $migration) {
        rehearsalAssert(copy($migration, $temporaryRoot . '/db/migrations/' . basename($migration)), 'Unable to copy migration ' . basename($migration) . '.');
    }
}

function rehearsalClearRuntimeEnvironment(): void
{
    foreach (['APP_ENV', 'APP_INSTALLED', 'APP_INSTALLATION_ID', 'APP_KEY', 'DB_DRIVER', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'OAUTH_PRIVATE_KEY_PATH', 'OAUTH_PUBLIC_KEY_PATH', 'OAUTH_ENCRYPTION_KEY'] as $name) {
        putenv($name);
        unset($_ENV[$name], $_SERVER[$name]);
    }
}

function rehearsalRemoveRoot(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $temporaryBase = realpath(sys_get_temp_dir());
    $resolved = realpath($path);
    rehearsalAssert($temporaryBase !== false && $resolved !== false, 'Unable to resolve temporary cleanup path.');
    $expectedPrefix = rtrim($temporaryBase, '\\/') . DIRECTORY_SEPARATOR . 'vertoad-install-rehearsal-';
    rehearsalAssert(str_starts_with($resolved, $expectedPrefix), 'Refusing to remove a path outside the rehearsal root.');

    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($items as $item) {
        $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        rehearsalAssert($removed, 'Unable to remove rehearsal path ' . $item->getPathname() . '.');
    }
    rehearsalAssert(rmdir($resolved), 'Unable to remove rehearsal root.');
}

function rehearsalAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
