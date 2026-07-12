<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;
use VertoAD\Install\MigrationRunnerInterface;

final readonly class MysqlInstallRehearsalMigrationRunner implements MigrationRunnerInterface
{
    public function __construct(
        private string $rootPath,
        private ?string $sslCaPath,
    ) {
    }

    public function migrate(#[\SensitiveParameter] array $databaseSettings): void
    {
        if (($databaseSettings['driver'] ?? null) !== 'pdo_mysql') {
            throw new InvalidArgumentException('The MySQL installation rehearsal only supports pdo_mysql.');
        }

        $config = new Config([
            'paths' => [
                'migrations' => $this->rootPath . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'migrations',
                'seeds' => $this->rootPath . DIRECTORY_SEPARATOR . 'db' . DIRECTORY_SEPARATOR . 'seeds',
            ],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'install-rehearsal',
                'install-rehearsal' => mysqlInstallRehearsalPhinxEnvironment($databaseSettings, $this->sslCaPath),
            ],
            'version_order' => 'creation',
        ]);

        (new Manager($config, new ArrayInput([]), new NullOutput()))->migrate('install-rehearsal');
    }
}

/**
 * @param array<string, mixed> $databaseSettings
 * @return array<string, bool|int|string>
 */
function mysqlInstallRehearsalPhinxEnvironment(
    #[\SensitiveParameter] array $databaseSettings,
    ?string $sslCaPath,
): array {
    $environment = [
        'adapter' => 'mysql',
        'host' => (string) ($databaseSettings['host'] ?? ''),
        'name' => (string) ($databaseSettings['database'] ?? ''),
        'user' => (string) ($databaseSettings['username'] ?? ''),
        'pass' => (string) ($databaseSettings['password'] ?? ''),
        'port' => (int) ($databaseSettings['port'] ?? 3306),
        'charset' => (string) ($databaseSettings['charset'] ?? 'utf8mb4'),
        'collation' => 'utf8mb4_unicode_ci',
    ];
    if ($sslCaPath !== null) {
        $environment['mysql_attr_ssl_ca'] = $sslCaPath;
        $environment['mysql_attr_ssl_verify_server_cert'] = true;
    }

    return $environment;
}

function mysqlInstallRehearsalIsLoopback(string $host): bool
{
    $host = strtolower(trim($host));
    if ($host === 'localhost' || $host === '::1') {
        return true;
    }

    if (filter_var($host, FILTER_VALIDATE_IP) === false) {
        return false;
    }

    $binary = inet_pton($host);
    if (!is_string($binary)) {
        return false;
    }
    if (strlen($binary) === 16) {
        return hash_equals(inet_pton('::1'), $binary);
    }

    return strlen($binary) === 4 && ord($binary[0]) === 127;
}

/** @return array<int, bool|int|string> */
function mysqlInstallRehearsalDriverOptions(?string $sslCaPath, int $connectTimeoutSeconds): array
{
    if ($connectTimeoutSeconds <= 0) {
        throw new InvalidArgumentException('MySQL rehearsal connect timeout must be positive.');
    }

    $options = [PDO::ATTR_TIMEOUT => $connectTimeoutSeconds];
    if ($sslCaPath !== null) {
        $options[mysqlInstallRehearsalPdoMysqlAttribute('ATTR_SSL_CA')] = $sslCaPath;
        $options[mysqlInstallRehearsalPdoMysqlAttribute('ATTR_SSL_VERIFY_SERVER_CERT')] = true;
    }

    return $options;
}

function mysqlInstallRehearsalPdoMysqlAttribute(string $name): int
{
    $constant = PHP_VERSION_ID >= 80400
        ? 'PDO\\Mysql::' . $name
        : 'PDO::MYSQL_' . $name;
    if (!defined($constant)) {
        throw new RuntimeException('Required PDO MySQL attribute is unavailable: ' . $name . '.');
    }

    return (int) constant($constant);
}

/**
 * @param array{host: string, port: int, username: string, password: string, database?: string} $database
 * @param array<int, bool|int|string> $driverOptions
 * @return array<string, mixed>
 */
function mysqlInstallRehearsalDoctrineParams(
    #[\SensitiveParameter] array $database,
    array $driverOptions,
    string $databaseName,
): array {
    return [
        'driver' => 'pdo_mysql',
        'host' => $database['host'],
        'port' => $database['port'],
        'dbname' => $databaseName,
        'user' => $database['username'],
        'password' => $database['password'],
        'charset' => 'utf8mb4',
        'driverOptions' => $driverOptions,
    ];
}

/**
 * @param array{host: string, port: int, username: string, password: string} $database
 * @param array<int, bool|int|string> $driverOptions
 */
function mysqlInstallRehearsalConnect(
    #[\SensitiveParameter] array $database,
    array $driverOptions,
    string $databaseName,
): Connection {
    return DriverManager::getConnection(
        mysqlInstallRehearsalDoctrineParams($database, $driverOptions, $databaseName),
    );
}

/**
 * @param list<string> $command
 * @param array<string, string> $environment
 * @return array{exit_code: int, stdout: string, stderr: string, timed_out: bool}
 */
function mysqlInstallRehearsalRunProcess(
    array $command,
    string $workingDirectory,
    array $environment,
    int $timeoutSeconds,
): array {
    if ($command === [] || trim($command[0] ?? '') === '') {
        throw new InvalidArgumentException('Process command must not be empty.');
    }
    if (!is_dir($workingDirectory)) {
        throw new InvalidArgumentException('Process working directory does not exist.');
    }
    if ($timeoutSeconds <= 0) {
        throw new InvalidArgumentException('Process timeout must be positive.');
    }

    $process = proc_open(
        $command,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        $environment,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start the rehearsal child process.');
    }

    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $stdout = '';
    $stderr = '';
    $deadline = microtime(true) + $timeoutSeconds;
    $timedOut = false;
    $status = proc_get_status($process);

    while ($status['running']) {
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (microtime(true) >= $deadline) {
            $timedOut = true;
            proc_terminate($process);
            break;
        }

        usleep(10_000);
        $status = proc_get_status($process);
    }

    if ($timedOut) {
        $terminationDeadline = microtime(true) + 5;
        do {
            usleep(10_000);
            $status = proc_get_status($process);
        } while ($status['running'] && microtime(true) < $terminationDeadline);
        if ($status['running']) {
            proc_terminate($process, 9);
            $forceTerminationDeadline = microtime(true) + 5;
            do {
                usleep(10_000);
                $status = proc_get_status($process);
            } while ($status['running'] && microtime(true) < $forceTerminationDeadline);
        }
    }

    $stdout .= (string) stream_get_contents($pipes[1]);
    $stderr .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $statusExitCode = (int) ($status['exitcode'] ?? -1);
    $closeExitCode = proc_close($process);
    $exitCode = $statusExitCode >= 0 ? $statusExitCode : $closeExitCode;
    if ($timedOut) {
        $exitCode = 124;
    }

    return [
        'exit_code' => $exitCode,
        'stdout' => $stdout,
        'stderr' => $stderr,
        'timed_out' => $timedOut,
    ];
}

/** @return list<string> */
function mysqlInstallRehearsalRuntimeEnvironmentNames(): array
{
    return [
        'APPDATA',
        'COMSPEC',
        'HOME',
        'LANG',
        'LC_ALL',
        'LD_LIBRARY_PATH',
        'LOCALAPPDATA',
        'PATH',
        'PATHEXT',
        'PHPRC',
        'PHP_INI_SCAN_DIR',
        'SSL_CERT_DIR',
        'SSL_CERT_FILE',
        'SYSTEMDRIVE',
        'SYSTEMROOT',
        'TEMP',
        'TMP',
        'TMPDIR',
        'TZ',
        'USER',
        'USERNAME',
        'USERPROFILE',
        'WINDIR',
    ];
}

/**
 * Remove caller application, database, CI, and unrelated secret variables from this process.
 */
function mysqlInstallRehearsalSanitizeCurrentEnvironment(): void
{
    $allowed = array_fill_keys(mysqlInstallRehearsalRuntimeEnvironmentNames(), true);
    $current = getenv();
    if (!is_array($current)) {
        return;
    }

    foreach ($current as $name => $_value) {
        $name = (string) $name;
        if (isset($allowed[strtoupper($name)])) {
            continue;
        }
        if (!putenv($name)) {
            throw new RuntimeException('Unable to sanitize the rehearsal process environment.');
        }
        unset($_ENV[$name], $_SERVER[$name]);
    }
}

/**
 * Build a deterministic child environment without application or database variables inherited from the caller.
 *
 * @param array<string, string> $overrides
 * @return array<string, string>
 */
function mysqlInstallRehearsalCleanProcessEnvironment(array $overrides): array
{
    $allowed = array_fill_keys(mysqlInstallRehearsalRuntimeEnvironmentNames(), true);
    $environment = [];
    $current = getenv();
    if (is_array($current)) {
        foreach ($current as $name => $value) {
            if (isset($allowed[strtoupper((string) $name)])) {
                $environment[(string) $name] = (string) $value;
            }
        }
    }

    foreach ($overrides as $name => $value) {
        foreach (array_keys($environment) as $existingName) {
            if (strcasecmp($existingName, $name) === 0) {
                unset($environment[$existingName]);
            }
        }
        $environment[$name] = $value;
    }

    return $environment;
}

/** @param list<string> $secrets */
function mysqlInstallRehearsalRedact(string $value, array $secrets): string
{
    $secrets = array_values(array_unique(array_filter(
        $secrets,
        static fn (string $secret): bool => $secret !== '',
    )));
    usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    return str_replace($secrets, '[REDACTED]', $value);
}
