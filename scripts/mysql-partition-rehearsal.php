<?php

declare(strict_types=1);

use Doctrine\DBAL\DriverManager;
use VertoAD\Service\Partition\EventTablePartitionMaintainer;

$autoloadPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

if (!defined('VERTOAD_MYSQL_PARTITION_REHEARSAL_TESTING')) {
    exit(mysqlPartitionRehearsalMain($argv));
}

/**
 * @param list<string> $argv
 */
function mysqlPartitionRehearsalMain(array $argv): int
{
    $rootPath = dirname(__DIR__);
    $phinxConfigPath = $rootPath . DIRECTORY_SEPARATOR . 'phinx.php';
    if (!is_file($phinxConfigPath)) {
        fwrite(STDERR, "Phinx config not found: {$phinxConfigPath}\n");

        return 1;
    }

    $phinxConfig = require $phinxConfigPath;
    if (!is_array($phinxConfig)) {
        fwrite(STDERR, "Phinx config did not return an array: {$phinxConfigPath}\n");

        return 1;
    }

    $configResult = mysqlPartitionRehearsalConfig($argv, $_ENV + getenv(), $rootPath, $phinxConfig);
    if (!$configResult['ok']) {
        fwrite(STDERR, $configResult['error'] . PHP_EOL);

        return 1;
    }

    /** @var array<string, mixed> $config */
    $config = $configResult['config'];
    try {
        mysqlPartitionRehearsalProbeDatabase($config['database']);
        if ($config['reset_database']) {
            mysqlPartitionRehearsalResetDatabase($config['database']);
        }

        $phinx = mysqlPartitionRehearsalRunCommand(mysqlPartitionRehearsalPhinxCommand($config), $rootPath);
        echo $phinx['output'];
        if ($phinx['exit_code'] !== 0) {
            return $phinx['exit_code'];
        }

        $connection = DriverManager::getConnection(mysqlPartitionRehearsalDoctrineParams($config['database']));
        $metrics = (new EventTablePartitionMaintainer(
            $connection,
            $config['lookahead_months'],
            $config['now'],
        ))->maintain();
        if (($metrics['protected_tables'] ?? 0) > 0) {
            fwrite(STDERR, 'Partition maintenance protected p_future because it contains rows: ' . json_encode(
                $metrics,
                JSON_THROW_ON_ERROR,
            ) . PHP_EOL);

            return 1;
        }

        $partitions = mysqlPartitionRehearsalPartitions($connection);
        mysqlPartitionRehearsalAssertPartitions($partitions, $config['lookahead_months'], $config['now']);
        echo json_encode([
            'status' => 'ok',
            'environment' => $config['environment'],
            'database' => $config['database']['name'],
            'lookahead_months' => $config['lookahead_months'],
            'metrics' => $metrics,
            'partitions' => $partitions,
        ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;

        return 0;
    } catch (Throwable $exception) {
        fwrite(STDERR, $exception->getMessage() . PHP_EOL);

        return 1;
    }
}

/**
 * @param list<string> $argv
 * @param array<string, mixed> $env
 * @param array<string, mixed> $phinxConfig
 * @return array{ok: bool, error?: string, config?: array<string, mixed>}
 */
function mysqlPartitionRehearsalConfig(array $argv, array $env, string $rootPath, array $phinxConfig): array
{
    if ((string) ($env['VERTOAD_MYSQL_PARTITION_REHEARSAL'] ?? '') !== '1') {
        return [
            'ok' => false,
            'error' => 'Set VERTOAD_MYSQL_PARTITION_REHEARSAL=1 before running the MySQL partition rehearsal.',
        ];
    }

    $options = mysqlPartitionRehearsalOptions($argv);
    $environment = (string) (
        $options['environment']
        ?? $phinxConfig['environments']['default_environment']
        ?? 'testing'
    );
    $lookaheadMonths = (int) (
        $options['lookahead-months']
        ?? $env['CRON_PARTITION_MAINTENANCE_LOOKAHEAD_MONTHS']
        ?? 3
    );
    if ($lookaheadMonths <= 0) {
        return [
            'ok' => false,
            'error' => 'Partition maintenance lookahead months must be positive.',
        ];
    }
    $connectTimeoutSeconds = (int) (
        $options['connect-timeout-seconds']
        ?? $env['VERTOAD_MYSQL_PARTITION_REHEARSAL_CONNECT_TIMEOUT_SECONDS']
        ?? 3
    );
    if ($connectTimeoutSeconds <= 0) {
        return [
            'ok' => false,
            'error' => 'Partition rehearsal connect timeout seconds must be positive.',
        ];
    }

    $database = $phinxConfig['environments'][$environment] ?? null;
    if (!is_array($database)) {
        return [
            'ok' => false,
            'error' => sprintf('Phinx environment "%s" was not found.', $environment),
        ];
    }

    $databaseName = (string) ($database['name'] ?? '');
    $now = null;
    if (isset($options['now'])) {
        try {
            $now = (new DateTimeImmutable((string) $options['now'], new DateTimeZone('UTC')))
                ->setTimezone(new DateTimeZone('UTC'));
        } catch (Throwable $exception) {
            return [
                'ok' => false,
                'error' => sprintf('Invalid rehearsal now timestamp "%s": %s', (string) $options['now'], $exception->getMessage()),
            ];
        }
    }

    if (
        !mysqlPartitionRehearsalDatabaseNameIsSafe($databaseName)
        && (string) ($env['VERTOAD_MYSQL_PARTITION_REHEARSAL_ALLOW_NON_TEST_DB'] ?? '') !== '1'
    ) {
        return [
            'ok' => false,
            'error' => sprintf(
                'Refusing to run partition rehearsal against database "%s"; use a test/dev/rehearsal database name or set VERTOAD_MYSQL_PARTITION_REHEARSAL_ALLOW_NON_TEST_DB=1.',
                $databaseName,
            ),
        ];
    }
    $database['attr_timeout'] = $connectTimeoutSeconds;

    return [
        'ok' => true,
        'config' => [
            'root_path' => $rootPath,
            'phinx_config_path' => $rootPath . DIRECTORY_SEPARATOR . 'phinx.php',
            'environment' => $environment,
            'lookahead_months' => $lookaheadMonths,
            'connect_timeout_seconds' => $connectTimeoutSeconds,
            'reset_database' => array_key_exists('reset-database', $options),
            'now' => $now,
            'database' => $database,
        ],
    ];
}

function mysqlPartitionRehearsalDatabaseNameIsSafe(string $databaseName): bool
{
    return preg_match('/(?:^|_)(test|testing|dev|local|sandbox|rehearsal)$/i', $databaseName) === 1;
}

/**
 * @param array<string, mixed> $config
 * @return list<string>
 */
function mysqlPartitionRehearsalPhinxCommand(array $config): array
{
    return [
        PHP_BINARY,
        $config['root_path'] . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'phinx',
        'migrate',
        '-e',
        (string) $config['environment'],
        '-c',
        (string) $config['phinx_config_path'],
    ];
}

/**
 * @param list<string> $argv
 * @return array<string, string|true>
 */
function mysqlPartitionRehearsalOptions(array $argv): array
{
    $options = [];
    for ($i = 1, $count = count($argv); $i < $count; ++$i) {
        $arg = $argv[$i];
        if ($arg === '--reset-database') {
            $options['reset-database'] = true;
            continue;
        }

        if (str_starts_with($arg, '--environment=')) {
            $options['environment'] = substr($arg, strlen('--environment='));
            continue;
        }

        if ($arg === '-e' && isset($argv[$i + 1])) {
            $options['environment'] = $argv[++$i];
            continue;
        }

        if (str_starts_with($arg, '--lookahead-months=')) {
            $options['lookahead-months'] = substr($arg, strlen('--lookahead-months='));
            continue;
        }

        if (str_starts_with($arg, '--connect-timeout-seconds=')) {
            $options['connect-timeout-seconds'] = substr($arg, strlen('--connect-timeout-seconds='));
            continue;
        }

        if (str_starts_with($arg, '--now=')) {
            $options['now'] = substr($arg, strlen('--now='));
        }
    }

    return $options;
}

/**
 * @param array<string, mixed> $database
 */
function mysqlPartitionRehearsalResetDatabase(array $database): void
{
    $name = (string) ($database['name'] ?? '');
    if (!mysqlPartitionRehearsalDatabaseNameIsSafe($name)) {
        throw new RuntimeException('Refusing to reset unsafe database name: ' . $name);
    }

    $pdo = mysqlPartitionRehearsalCreatePdo($database);
    $quotedName = mysqlPartitionRehearsalQuoteIdentifier($name);
    $charset = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($database['charset'] ?? 'utf8mb4')) ?: 'utf8mb4';
    $collation = preg_replace('/[^a-zA-Z0-9_]/', '', (string) ($database['collation'] ?? 'utf8mb4_unicode_ci')) ?: 'utf8mb4_unicode_ci';

    $pdo->exec('DROP DATABASE IF EXISTS ' . $quotedName);
    $pdo->exec(sprintf(
        'CREATE DATABASE %s CHARACTER SET %s COLLATE %s',
        $quotedName,
        $charset,
        $collation,
    ));
}

/**
 * @param array<string, mixed> $database
 */
function mysqlPartitionRehearsalProbeDatabase(array $database): void
{
    $pdo = mysqlPartitionRehearsalCreatePdo($database);
    $pdo->query('SELECT 1');
}

/**
 * @param array<string, mixed> $database
 */
function mysqlPartitionRehearsalCreatePdo(array $database): PDO
{
    $dsn = sprintf(
        'mysql:host=%s;port=%d;charset=%s',
        (string) ($database['host'] ?? '127.0.0.1'),
        (int) ($database['port'] ?? 3306),
        (string) ($database['charset'] ?? 'utf8mb4'),
    );

    return new PDO(
        $dsn,
        (string) ($database['user'] ?? ''),
        (string) ($database['pass'] ?? ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => (int) ($database['attr_timeout'] ?? 3),
        ],
    );
}

function mysqlPartitionRehearsalQuoteIdentifier(string $identifier): string
{
    return '`' . str_replace('`', '``', $identifier) . '`';
}

/**
 * @param array<string, mixed> $database
 * @return array<string, mixed>
 */
function mysqlPartitionRehearsalDoctrineParams(array $database): array
{
    return [
        'driver' => 'pdo_mysql',
        'host' => (string) ($database['host'] ?? '127.0.0.1'),
        'port' => (int) ($database['port'] ?? 3306),
        'dbname' => (string) ($database['name'] ?? ''),
        'user' => (string) ($database['user'] ?? ''),
        'password' => (string) ($database['pass'] ?? ''),
        'charset' => (string) ($database['charset'] ?? 'utf8mb4'),
        'driverOptions' => [
            PDO::ATTR_TIMEOUT => (int) ($database['attr_timeout'] ?? 3),
        ],
    ];
}

/**
 * @param list<string> $command
 * @return array{exit_code: int, output: string}
 */
function mysqlPartitionRehearsalRunCommand(array $command, string $cwd): array
{
    $descriptorSpec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptorSpec, $pipes, $cwd);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start Phinx migration process.');
    }

    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'output' => $output,
    ];
}

/**
 * @return array<string, list<string>>
 */
function mysqlPartitionRehearsalPartitions(\Doctrine\DBAL\Connection $connection): array
{
    $partitions = [];
    foreach (['raw_events', 'ad_serving_events'] as $table) {
        $partitions[$table] = array_map(
            static fn (mixed $partition): string => (string) $partition,
            $connection->fetchFirstColumn(
                <<<'SQL'
SELECT partition_name
FROM information_schema.partitions
WHERE table_schema = DATABASE()
  AND table_name = :table_name
  AND partition_name IS NOT NULL
ORDER BY partition_ordinal_position ASC
SQL,
                ['table_name' => $table],
            ),
        );
    }

    return $partitions;
}

/**
 * @param array<string, list<string>> $partitions
 */
function mysqlPartitionRehearsalAssertPartitions(array $partitions, int $lookaheadMonths, ?DateTimeImmutable $now = null): void
{
    $expectedFuturePartitions = mysqlPartitionRehearsalExpectedPartitionNames($lookaheadMonths, $now);
    foreach (['raw_events', 'ad_serving_events'] as $table) {
        $actual = $partitions[$table] ?? [];
        if (!in_array('p_future', $actual, true)) {
            throw new RuntimeException(sprintf('%s is missing p_future after partition rehearsal.', $table));
        }

        foreach ($expectedFuturePartitions as $partitionName) {
            if (!in_array($partitionName, $actual, true)) {
                throw new RuntimeException(sprintf(
                    '%s is missing expected future partition %s after partition rehearsal.',
                    $table,
                    $partitionName,
                ));
            }
        }
    }
}

/**
 * @return list<string>
 */
function mysqlPartitionRehearsalExpectedPartitionNames(int $lookaheadMonths, ?DateTimeImmutable $now = null): array
{
    $now = ($now ?? new DateTimeImmutable('now', new DateTimeZone('UTC')))->setTimezone(new DateTimeZone('UTC'));
    $currentMonthStart = $now->modify('first day of this month')->setTime(0, 0);
    $partitions = [];
    for ($offset = 1; $offset <= $lookaheadMonths; ++$offset) {
        $partitions[] = 'p' . $currentMonthStart->modify('+' . $offset . ' months')->format('Ym');
    }

    return $partitions;
}
