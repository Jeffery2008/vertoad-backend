<?php

declare(strict_types=1);

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Service\Partition\EventTablePartitionMaintainer;

$autoloadPath = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (is_file($autoloadPath)) {
    require_once $autoloadPath;
}

const VERTOAD_PARTITION_REHEARSAL_DATABASE_PATTERN = '/^vertoad_partition_rehearsal_[a-f0-9]{32}$/D';
const VERTOAD_PARTITION_REHEARSAL_NOW = '2026-12-15T00:00:00+00:00';
const VERTOAD_PARTITION_REHEARSAL_LOOKAHEAD_MONTHS = 3;

/**
 * @var array<string, array{id_column: string, id_prefix: string}>
 */
const VERTOAD_PARTITION_REHEARSAL_TABLES = [
    'raw_events' => [
        'id_column' => 'event_uuid',
        'id_prefix' => 'raw-',
    ],
    'ad_serving_events' => [
        'id_column' => 'event_id',
        'id_prefix' => 'serving-',
    ],
];

/**
 * @var list<array{label: string, occurred_at: string}>
 */
const VERTOAD_PARTITION_REHEARSAL_FIXTURES = [
    ['label' => 'dec-end', 'occurred_at' => '2026-12-31 23:59:59'],
    ['label' => 'jan-start', 'occurred_at' => '2027-01-01 00:00:00'],
    ['label' => 'jan-end', 'occurred_at' => '2027-01-31 23:59:59'],
    ['label' => 'feb-start', 'occurred_at' => '2027-02-01 00:00:00'],
    ['label' => 'feb-end', 'occurred_at' => '2027-02-28 23:59:59'],
    ['label' => 'mar-start', 'occurred_at' => '2027-03-01 00:00:00'],
];

/**
 * @var array<string, array{start: string, end: string, labels: list<string>, partitions: list<string>}>
 */
const VERTOAD_PARTITION_REHEARSAL_QUERIES = [
    'january' => [
        'start' => '2027-01-01 00:00:00',
        'end' => '2027-02-01 00:00:00',
        'labels' => ['jan-start', 'jan-end'],
        'partitions' => ['p202701'],
    ],
    'cross_month_boundary' => [
        'start' => '2026-12-31 23:59:59',
        'end' => '2027-02-01 00:00:01',
        'labels' => ['dec-end', 'jan-start', 'jan-end', 'feb-start'],
        'partitions' => ['p202612', 'p202701', 'p202702'],
    ],
    'february' => [
        'start' => '2027-02-01 00:00:00',
        'end' => '2027-03-01 00:00:00',
        'labels' => ['feb-start', 'feb-end'],
        'partitions' => ['p202702'],
    ],
    'march' => [
        'start' => '2027-03-01 00:00:00',
        'end' => '2027-04-01 00:00:00',
        'labels' => ['mar-start'],
        'partitions' => ['p202703'],
    ],
];

if (!defined('VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL_TESTING')) {
    exit(mysqlPartitionQueryRehearsalMain($argv));
}

/**
 * @param list<string> $argv
 */
function mysqlPartitionQueryRehearsalMain(array $argv): int
{
    $rootPath = dirname(__DIR__);
    $connection = null;
    $admin = null;
    $databaseName = null;
    $databaseCreated = false;
    $secrets = [];
    $cleanupFailures = [];
    $evidence = [
        'schema' => 'vertoad.mysql-partition-query-rehearsal.v1',
        'status' => 'failed',
        'mysql' => null,
        'database' => [
            'name' => null,
            'created' => false,
            'migration_count' => 0,
        ],
        'maintenance' => null,
        'queries' => null,
        'partition_row_counts' => null,
        'failure' => null,
        'cleanup' => [
            'database_was_created' => false,
            'database_drop_attempted' => false,
            'database_absence_verified' => false,
            'temporary_artifacts_created' => 0,
        ],
        'cleanup_failures' => [],
    ];

    try {
        EnvironmentLoader::load($rootPath);
        $processEnvironment = getenv();
        $configResult = mysqlPartitionQueryRehearsalConfig(
            $argv,
            $_ENV + (is_array($processEnvironment) ? $processEnvironment : []),
        );
        if (!$configResult['ok']) {
            throw new RuntimeException((string) $configResult['error']);
        }

        /** @var array{database_name: string, connect_timeout_seconds: int, database: array{host: string, port: int, username: string, password: string, charset: string}} $config */
        $config = $configResult['config'];
        $databaseName = $config['database_name'];
        $password = $config['database']['password'];
        $secrets = [$password, rawurlencode($password)];
        $evidence['database']['name'] = $databaseName;

        $admin = mysqlPartitionQueryRehearsalAdminPdo(
            $config['database'],
            $config['connect_timeout_seconds'],
        );
        $version = (string) $admin->query('SELECT VERSION()')->fetchColumn();
        if (preg_match('/^(8)\./D', $version, $matches) !== 1) {
            throw new RuntimeException('The partition query rehearsal requires MySQL 8.x.');
        }
        $evidence['mysql'] = [
            'version' => $version,
            'major_version' => (int) $matches[1],
        ];

        if (mysqlPartitionQueryRehearsalDatabaseExists($admin, $databaseName)) {
            throw new RuntimeException('Refusing to reuse an existing partition rehearsal database.');
        }

        mysqlPartitionQueryRehearsalCreateDatabase($admin, $databaseName);
        $databaseCreated = true;
        $evidence['database']['created'] = true;

        $settings = mysqlPartitionQueryRehearsalDatabaseSettings($config['database'], $databaseName);
        (new PhinxMigrationRunner($rootPath))->migrate($settings);
        $connection = DriverManager::getConnection(
            mysqlPartitionQueryRehearsalDoctrineParams(
                $config['database'],
                $databaseName,
                $config['connect_timeout_seconds'],
            ),
        );
        $connection->executeStatement("SET time_zone = '+00:00'");
        $evidence['database']['migration_count'] = (int) $connection->fetchOne('SELECT COUNT(*) FROM phinxlog');
        if ($evidence['database']['migration_count'] <= 0) {
            throw new RuntimeException('The disposable database did not record any migrations.');
        }

        $now = new DateTimeImmutable(VERTOAD_PARTITION_REHEARSAL_NOW);
        $maintainer = new EventTablePartitionMaintainer(
            $connection,
            VERTOAD_PARTITION_REHEARSAL_LOOKAHEAD_MONTHS,
            $now,
        );
        $firstMaintenance = $maintainer->maintain();
        mysqlPartitionQueryRehearsalAssertFirstMaintenance($firstMaintenance);
        $partitionsAfterFirst = mysqlPartitionQueryRehearsalPartitionSnapshot($connection);

        $secondMaintenance = $maintainer->maintain();
        mysqlPartitionQueryRehearsalAssertSecondMaintenance($secondMaintenance);
        $partitionsAfterSecond = mysqlPartitionQueryRehearsalPartitionSnapshot($connection);
        if ($partitionsAfterFirst !== $partitionsAfterSecond) {
            throw new RuntimeException('The second partition maintenance run changed partition topology.');
        }

        mysqlPartitionQueryRehearsalInsertFixtures($connection);
        $queries = mysqlPartitionQueryRehearsalRunQueries($connection);
        $partitionRowCounts = mysqlPartitionQueryRehearsalPartitionRowCounts($connection);

        $evidence['maintenance'] = [
            'now' => $now->format(DATE_ATOM),
            'lookahead_months' => VERTOAD_PARTITION_REHEARSAL_LOOKAHEAD_MONTHS,
            'first_run' => $firstMaintenance,
            'second_run' => $secondMaintenance,
            'topology_unchanged_on_second_run' => true,
            'partitions' => $partitionsAfterSecond,
        ];
        $evidence['queries'] = $queries;
        $evidence['partition_row_counts'] = $partitionRowCounts;
        $evidence['status'] = 'passed';
    } catch (Throwable $exception) {
        $evidence['failure'] = [
            'type' => $exception::class,
            'message' => mysqlPartitionQueryRehearsalRedact($exception->getMessage(), $secrets),
        ];
    } finally {
        if ($connection instanceof Connection) {
            try {
                $connection->close();
            } catch (Throwable) {
                $cleanupFailures[] = 'application_connection_close';
            }
        }

        $evidence['cleanup']['database_was_created'] = $databaseCreated;
        if ($admin instanceof PDO && is_string($databaseName) && $databaseCreated) {
            $evidence['cleanup']['database_drop_attempted'] = true;
            try {
                mysqlPartitionQueryRehearsalDropDatabase($admin, $databaseName);
            } catch (Throwable) {
                $cleanupFailures[] = 'database_drop';
            }

            try {
                $evidence['cleanup']['database_absence_verified'] = !mysqlPartitionQueryRehearsalDatabaseExists(
                    $admin,
                    $databaseName,
                );
                if (!$evidence['cleanup']['database_absence_verified']) {
                    $cleanupFailures[] = 'database_absence_verification';
                }
            } catch (Throwable) {
                $cleanupFailures[] = 'database_absence_verification';
            }
        }

        if ($cleanupFailures !== []) {
            $evidence['status'] = 'failed';
            $evidence['cleanup_failures'] = array_values(array_unique($cleanupFailures));
        }
    }

    echo json_encode(
        $evidence,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR,
    ) . PHP_EOL;

    return $evidence['status'] === 'passed' ? 0 : 1;
}

/**
 * @param list<string> $argv
 * @param array<string, mixed> $env
 * @return array{ok: bool, error?: string, config?: array<string, mixed>}
 */
function mysqlPartitionQueryRehearsalConfig(array $argv, #[SensitiveParameter] array $env): array
{
    try {
        $options = mysqlPartitionQueryRehearsalOptions($argv);
    } catch (Throwable $exception) {
        return ['ok' => false, 'error' => $exception->getMessage()];
    }

    if (!isset($options['execute']) && (string) ($env['VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL'] ?? '') !== '1') {
        return [
            'ok' => false,
            'error' => 'Pass --execute or set VERTOAD_MYSQL_PARTITION_QUERY_REHEARSAL=1 to run the destructive disposable-database rehearsal.',
        ];
    }

    $databaseName = isset($options['database-name'])
        ? (string) $options['database-name']
        : mysqlPartitionQueryRehearsalGeneratedDatabaseName();
    if (!mysqlPartitionQueryRehearsalDatabaseNameIsSafe($databaseName)) {
        return [
            'ok' => false,
            'error' => 'Refusing a database name outside the one-time partition rehearsal namespace.',
        ];
    }

    $port = mysqlPartitionQueryRehearsalPositiveInt(
        mysqlPartitionQueryRehearsalEnvironment(
            $env,
            'MYSQL_PARTITION_QUERY_REHEARSAL_PORT',
            'DB_PORT',
            '3306',
        ),
    );
    $connectTimeout = mysqlPartitionQueryRehearsalPositiveInt(
        (string) ($options['connect-timeout-seconds'] ?? mysqlPartitionQueryRehearsalEnvironment(
            $env,
            'MYSQL_PARTITION_QUERY_REHEARSAL_CONNECT_TIMEOUT_SECONDS',
            '',
            '5',
        )),
    );
    if ($port === null || $connectTimeout === null) {
        return ['ok' => false, 'error' => 'MySQL port and connect timeout must be positive integers.'];
    }

    $host = mysqlPartitionQueryRehearsalEnvironment(
        $env,
        'MYSQL_PARTITION_QUERY_REHEARSAL_HOST',
        'DB_HOST',
        '127.0.0.1',
    );
    $username = mysqlPartitionQueryRehearsalEnvironment(
        $env,
        'MYSQL_PARTITION_QUERY_REHEARSAL_USERNAME',
        'DB_USERNAME',
        '',
    );
    if (!mysqlPartitionQueryRehearsalHostIsSafe($host) || trim($username) === '') {
        return ['ok' => false, 'error' => 'MySQL host and username are required.'];
    }

    return [
        'ok' => true,
        'config' => [
            'database_name' => $databaseName,
            'connect_timeout_seconds' => $connectTimeout,
            'database' => [
                'host' => trim($host),
                'port' => $port,
                'username' => $username,
                'password' => mysqlPartitionQueryRehearsalEnvironment(
                    $env,
                    'MYSQL_PARTITION_QUERY_REHEARSAL_PASSWORD',
                    'DB_PASSWORD',
                    '',
                    trim: false,
                ),
                'charset' => 'utf8mb4',
            ],
        ],
    ];
}

/**
 * @param list<string> $argv
 * @return array<string, string|true>
 */
function mysqlPartitionQueryRehearsalOptions(array $argv): array
{
    $options = [];
    $valueOptions = ['database-name', 'connect-timeout-seconds'];
    for ($index = 1, $count = count($argv); $index < $count; ++$index) {
        $argument = $argv[$index];
        if ($argument === '--execute') {
            if (isset($options['execute'])) {
                throw new InvalidArgumentException('Duplicate rehearsal option.');
            }
            $options['execute'] = true;

            continue;
        }

        if (!str_starts_with($argument, '--') || !str_contains($argument, '=')) {
            throw new InvalidArgumentException('Unsupported rehearsal option.');
        }

        [$name, $value] = explode('=', substr($argument, 2), 2);
        if (!in_array($name, $valueOptions, true) || $value === '' || array_key_exists($name, $options)) {
            throw new InvalidArgumentException('Unsupported, empty, or duplicate rehearsal option.');
        }
        $options[$name] = $value;
    }

    return $options;
}

function mysqlPartitionQueryRehearsalGeneratedDatabaseName(): string
{
    return 'vertoad_partition_rehearsal_' . bin2hex(random_bytes(16));
}

function mysqlPartitionQueryRehearsalDatabaseNameIsSafe(string $databaseName): bool
{
    return preg_match(VERTOAD_PARTITION_REHEARSAL_DATABASE_PATTERN, $databaseName) === 1;
}

/**
 * @param array<string, mixed> $env
 */
function mysqlPartitionQueryRehearsalEnvironment(
    array $env,
    string $primary,
    string $fallback,
    string $default,
    bool $trim = true,
): string {
    $value = array_key_exists($primary, $env) ? (string) $env[$primary] : '';
    if ($value === '' && $fallback !== '' && array_key_exists($fallback, $env)) {
        $value = (string) $env[$fallback];
    }
    if ($value === '') {
        $value = $default;
    }

    return $trim ? trim($value) : $value;
}

function mysqlPartitionQueryRehearsalPositiveInt(string $value): ?int
{
    if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
        return null;
    }

    return (int) $value;
}

function mysqlPartitionQueryRehearsalHostIsSafe(string $host): bool
{
    $host = trim($host);

    return $host !== ''
        && strlen($host) <= 253
        && preg_match('/[;\x00-\x20]/D', $host) !== 1;
}

/**
 * @param array{host: string, port: int, username: string, password: string, charset: string} $database
 */
function mysqlPartitionQueryRehearsalAdminPdo(
    #[SensitiveParameter] array $database,
    int $connectTimeoutSeconds,
): PDO {
    return new PDO(
        sprintf(
            'mysql:host=%s;port=%d;charset=%s',
            $database['host'],
            $database['port'],
            $database['charset'],
        ),
        $database['username'],
        $database['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => $connectTimeoutSeconds,
        ],
    );
}

function mysqlPartitionQueryRehearsalDatabaseExists(PDO $admin, string $databaseName): bool
{
    if (!mysqlPartitionQueryRehearsalDatabaseNameIsSafe($databaseName)) {
        throw new InvalidArgumentException('Unsafe partition rehearsal database name.');
    }

    $statement = $admin->prepare(
        'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
    );
    $statement->execute([$databaseName]);

    return (int) $statement->fetchColumn() > 0;
}

function mysqlPartitionQueryRehearsalCreateDatabase(PDO $admin, string $databaseName): void
{
    if (!mysqlPartitionQueryRehearsalDatabaseNameIsSafe($databaseName)) {
        throw new InvalidArgumentException('Unsafe partition rehearsal database name.');
    }

    $admin->exec(
        'CREATE DATABASE `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    );
}

function mysqlPartitionQueryRehearsalDropDatabase(PDO $admin, string $databaseName): void
{
    if (!mysqlPartitionQueryRehearsalDatabaseNameIsSafe($databaseName)) {
        throw new InvalidArgumentException('Unsafe partition rehearsal database name.');
    }

    $admin->exec('DROP DATABASE `' . $databaseName . '`');
}

/**
 * @param array{host: string, port: int, username: string, password: string, charset: string} $database
 * @return array<string, int|string>
 */
function mysqlPartitionQueryRehearsalDatabaseSettings(
    #[SensitiveParameter] array $database,
    string $databaseName,
): array {
    return [
        'driver' => 'pdo_mysql',
        'host' => $database['host'],
        'port' => $database['port'],
        'database' => $databaseName,
        'username' => $database['username'],
        'password' => $database['password'],
        'charset' => $database['charset'],
    ];
}

/**
 * @param array{host: string, port: int, username: string, password: string, charset: string} $database
 * @return array<string, mixed>
 */
function mysqlPartitionQueryRehearsalDoctrineParams(
    #[SensitiveParameter] array $database,
    string $databaseName,
    int $connectTimeoutSeconds,
): array {
    return [
        'driver' => 'pdo_mysql',
        'host' => $database['host'],
        'port' => $database['port'],
        'dbname' => $databaseName,
        'user' => $database['username'],
        'password' => $database['password'],
        'charset' => $database['charset'],
        'driverOptions' => [PDO::ATTR_TIMEOUT => $connectTimeoutSeconds],
    ];
}

/**
 * @param array<string, int|string|null> $metrics
 */
function mysqlPartitionQueryRehearsalAssertFirstMaintenance(array $metrics): void
{
    $expected = [
        'tables_checked' => 2,
        'partitions_created' => 6,
        'tables_reorganized' => 2,
        'tables_already_current' => 0,
        'protected_tables' => 0,
    ];
    foreach ($expected as $name => $value) {
        if (($metrics[$name] ?? null) !== $value) {
            throw new RuntimeException('The first partition maintenance run did not create the expected topology.');
        }
    }
}

/**
 * @param array<string, int|string|null> $metrics
 */
function mysqlPartitionQueryRehearsalAssertSecondMaintenance(array $metrics): void
{
    $expected = [
        'tables_checked' => 2,
        'partitions_created' => 0,
        'tables_reorganized' => 0,
        'tables_already_current' => 2,
        'protected_tables' => 0,
    ];
    foreach ($expected as $name => $value) {
        if (($metrics[$name] ?? null) !== $value) {
            throw new RuntimeException('The second partition maintenance run was not idempotent.');
        }
    }
}

/**
 * @return array<string, list<string>>
 */
function mysqlPartitionQueryRehearsalPartitionSnapshot(Connection $connection): array
{
    $snapshot = [];
    foreach (array_keys(VERTOAD_PARTITION_REHEARSAL_TABLES) as $table) {
        $snapshot[$table] = array_map(
            static fn (mixed $partition): string => (string) $partition,
            $connection->fetchFirstColumn(
                <<<'SQL'
SELECT partition_name
FROM information_schema.partitions
WHERE table_schema = DATABASE()
  AND table_name = ?
  AND partition_name IS NOT NULL
ORDER BY partition_ordinal_position
SQL,
                [$table],
            ),
        );

        foreach (['p202701', 'p202702', 'p202703', 'p_future'] as $requiredPartition) {
            if (!in_array($requiredPartition, $snapshot[$table], true)) {
                throw new RuntimeException('Partition maintenance left an expected partition absent.');
            }
        }
    }

    return $snapshot;
}

function mysqlPartitionQueryRehearsalInsertFixtures(Connection $connection): void
{
    $connection->beginTransaction();
    try {
        foreach (VERTOAD_PARTITION_REHEARSAL_FIXTURES as $fixture) {
            $connection->insert('raw_events', [
                'event_uuid' => 'raw-' . $fixture['label'],
                'event_type' => 'partition_rehearsal',
                'occurred_at' => $fixture['occurred_at'],
                'payload_json' => json_encode(
                    ['fixture' => $fixture['label']],
                    JSON_THROW_ON_ERROR,
                ),
            ]);
            $connection->insert('ad_serving_events', [
                'event_type' => 'partition_rehearsal',
                'event_id' => 'serving-' . $fixture['label'],
                'decision_id' => 'partition-rehearsal-decision',
                'site_id' => 1,
                'slot_id' => 1,
                'viewer_id' => 'partition-rehearsal-viewer',
                'occurred_at' => $fixture['occurred_at'],
                'valid' => 1,
            ]);
        }
        $connection->commit();
    } catch (Throwable $exception) {
        $connection->rollBack();

        throw $exception;
    }
}

/**
 * @return array<string, array<string, array{row_ids: list<string>, partitions: list<string>, p_future_scanned: bool}>>
 */
function mysqlPartitionQueryRehearsalRunQueries(Connection $connection): array
{
    $evidence = [];
    foreach (VERTOAD_PARTITION_REHEARSAL_TABLES as $table => $tableConfig) {
        foreach (VERTOAD_PARTITION_REHEARSAL_QUERIES as $name => $query) {
            $sql = mysqlPartitionQueryRehearsalTimeRangeSql($table);
            $parameters = [$query['start'], $query['end']];
            $actualIds = array_map(
                static fn (mixed $id): string => (string) $id,
                $connection->fetchFirstColumn($sql, $parameters),
            );
            $expectedIds = array_map(
                static fn (string $label): string => $tableConfig['id_prefix'] . $label,
                $query['labels'],
            );
            mysqlPartitionQueryRehearsalAssertRows($actualIds, $expectedIds);

            $partitions = mysqlPartitionQueryRehearsalExtractPartitions(
                $connection->fetchAllAssociative('EXPLAIN ' . $sql, $parameters),
            );
            mysqlPartitionQueryRehearsalAssertPartitions($partitions, $query['partitions']);

            $evidence[$table][$name] = [
                'row_ids' => $actualIds,
                'partitions' => $partitions,
                'p_future_scanned' => false,
            ];
        }
    }

    return $evidence;
}

function mysqlPartitionQueryRehearsalTimeRangeSql(string $table): string
{
    $tableConfig = VERTOAD_PARTITION_REHEARSAL_TABLES[$table] ?? null;
    if (!is_array($tableConfig)) {
        throw new InvalidArgumentException('Unsupported partition rehearsal table.');
    }
    $idColumn = $tableConfig['id_column'];

    return sprintf(
        'SELECT `%1$s` AS fixture_id FROM `%2$s` WHERE occurred_at >= ? AND occurred_at < ? '
        . 'ORDER BY occurred_at ASC, `%1$s` ASC',
        $idColumn,
        $table,
    );
}

/**
 * @param list<string> $actual
 * @param list<string> $expected
 */
function mysqlPartitionQueryRehearsalAssertRows(array $actual, array $expected): void
{
    if ($actual !== $expected) {
        throw new RuntimeException('A partitioned time-range query returned an unexpected result set.');
    }
}

/**
 * @param list<array<string, mixed>> $explainRows
 * @return list<string>
 */
function mysqlPartitionQueryRehearsalExtractPartitions(array $explainRows): array
{
    $partitions = [];
    foreach ($explainRows as $row) {
        $value = $row['partitions'] ?? $row['PARTITIONS'] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException('EXPLAIN did not report scanned partitions.');
        }
        foreach (explode(',', $value) as $partition) {
            $partition = trim($partition);
            if ($partition !== '' && !in_array($partition, $partitions, true)) {
                $partitions[] = $partition;
            }
        }
    }
    if ($partitions === []) {
        throw new RuntimeException('EXPLAIN returned no scanned partitions.');
    }

    return $partitions;
}

/**
 * @param list<string> $actual
 * @param list<string> $expected
 */
function mysqlPartitionQueryRehearsalAssertPartitions(array $actual, array $expected): void
{
    if (in_array('p_future', $actual, true)) {
        throw new RuntimeException('A bounded hot-data query unexpectedly scanned p_future.');
    }
    if ($actual !== $expected) {
        throw new RuntimeException('EXPLAIN scanned an unexpected partition set.');
    }
}

/**
 * @return array<string, array<string, int>>
 */
function mysqlPartitionQueryRehearsalPartitionRowCounts(Connection $connection): array
{
    $expected = [
        'p202612' => 1,
        'p202701' => 2,
        'p202702' => 2,
        'p202703' => 1,
        'p_future' => 0,
    ];
    $counts = [];
    foreach (array_keys(VERTOAD_PARTITION_REHEARSAL_TABLES) as $table) {
        foreach ($expected as $partition => $expectedCount) {
            $count = (int) $connection->fetchOne(sprintf(
                'SELECT COUNT(*) FROM `%s` PARTITION (`%s`)',
                $table,
                $partition,
            ));
            if ($count !== $expectedCount) {
                throw new RuntimeException('A fixture row landed in an unexpected physical partition.');
            }
            $counts[$table][$partition] = $count;
        }
    }

    return $counts;
}

/**
 * @param list<string> $secrets
 */
function mysqlPartitionQueryRehearsalRedact(string $value, array $secrets): string
{
    $secrets = array_values(array_unique(array_filter(
        $secrets,
        static fn (string $secret): bool => $secret !== '',
    )));
    usort($secrets, static fn (string $left, string $right): int => strlen($right) <=> strlen($left));

    return str_replace($secrets, '[REDACTED]', $value);
}
