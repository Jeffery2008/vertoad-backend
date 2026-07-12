<?php

declare(strict_types=1);

namespace VertoAD\Tests\Acceptance\ArchiveColdQuery;

use Aws\Credentials\Credentials;
use Aws\S3\S3Client;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use SensitiveParameter;
use Throwable;
use VertoAD\Bootstrap\EnvironmentLoader;
use VertoAD\Infrastructure\Database\ConnectionFactory;
use VertoAD\Infrastructure\Storage\S3ArchiveObjectStorage;
use VertoAD\Install\PhinxMigrationRunner;
use VertoAD\Repository\Archive\ArchiveRepositoryInterface;
use VertoAD\Repository\Archive\DatabaseArchiveRepository;
use VertoAD\Service\Archive\ArchiveJob;
use VertoAD\Service\Archive\ArchiveObjectStorageInterface;
use VertoAD\Service\Archive\ColdQueryService;
use VertoAD\Service\Archive\DuckDbCliArchiveWriter;
use VertoAD\Service\Archive\DuckDbCliColdQueryRunner;
use VertoAD\Service\Archive\ProcessArchiveCommandRunner;
use VertoAD\Service\Cron\ArchiveParquetJob;
use VertoAD\Service\Cron\DuckDbColdQueryJob;

final readonly class ArchiveColdQueryAcceptanceConfig
{
    private function __construct(
        public string $mysqlHost,
        public int $mysqlPort,
        public string $mysqlUsername,
        #[SensitiveParameter]
        private string $mysqlPassword,
        public string $s3Endpoint,
        public string $s3Region,
        public string $s3Bucket,
        #[SensitiveParameter]
        private string $s3AccessKeyId,
        #[SensitiveParameter]
        private string $s3SecretAccessKey,
        public bool $s3PathStyleEndpoint,
        public string $duckDbBinary,
        public int $commandTimeoutSeconds,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $driver = self::acceptanceEnvironment('DB_DRIVER', 'DB_DRIVER', 'pdo_mysql');
        if ($driver !== 'pdo_mysql') {
            throw new \RuntimeException('The archive cold-query acceptance runner requires DB_DRIVER=pdo_mysql.');
        }

        $mysqlUsername = self::acceptanceEnvironment('DB_USERNAME', 'DB_USERNAME', '');
        if ($mysqlUsername === '') {
            throw new \RuntimeException('A MySQL acceptance username is required.');
        }

        $endpoint = rtrim(self::acceptanceEnvironment('S3_ENDPOINT', 'S3_ENDPOINT', ''), '/');
        $endpointParts = parse_url($endpoint);
        if (
            !is_array($endpointParts)
            || !in_array(strtolower((string) ($endpointParts['scheme'] ?? '')), ['http', 'https'], true)
            || trim((string) ($endpointParts['host'] ?? '')) === ''
            || isset($endpointParts['user'])
            || isset($endpointParts['pass'])
            || isset($endpointParts['query'])
            || isset($endpointParts['fragment'])
        ) {
            throw new \RuntimeException('A valid credential-free S3-compatible endpoint is required.');
        }

        $bucket = self::acceptanceEnvironment('S3_BUCKET', 'S3_BUCKET', '');
        $accessKey = self::acceptanceEnvironment('S3_ACCESS_KEY_ID', 'S3_ACCESS_KEY_ID', '');
        $secretKey = self::acceptanceEnvironment('S3_SECRET_ACCESS_KEY', 'S3_SECRET_ACCESS_KEY', '');
        if ($bucket === '' || $accessKey === '' || $secretKey === '') {
            throw new \RuntimeException('S3-compatible bucket credentials are required for archive acceptance.');
        }

        $duckDbBinary = self::acceptanceEnvironment('DUCKDB_BINARY', 'ARCHIVE_DUCKDB_BINARY', '');
        if ($duckDbBinary === '' || !is_file($duckDbBinary)) {
            throw new \RuntimeException('ARCHIVE_DUCKDB_BINARY must name an existing real DuckDB executable.');
        }

        $pathStyle = filter_var(
            self::acceptanceEnvironment('S3_PATH_STYLE_ENDPOINT', 'S3_PATH_STYLE_ENDPOINT', 'true'),
            FILTER_VALIDATE_BOOL,
            FILTER_NULL_ON_FAILURE,
        );
        if ($pathStyle === null) {
            throw new \RuntimeException('S3_PATH_STYLE_ENDPOINT must be a boolean value.');
        }

        return new self(
            mysqlHost: self::acceptanceEnvironment('DB_HOST', 'DB_HOST', '127.0.0.1'),
            mysqlPort: self::positiveInt(
                self::acceptanceEnvironment('DB_PORT', 'DB_PORT', '3306'),
                'MySQL acceptance port',
            ),
            mysqlUsername: $mysqlUsername,
            mysqlPassword: self::acceptanceEnvironment('DB_PASSWORD', 'DB_PASSWORD', ''),
            s3Endpoint: $endpoint,
            s3Region: self::acceptanceEnvironment('S3_REGION', 'S3_REGION', 'auto'),
            s3Bucket: $bucket,
            s3AccessKeyId: $accessKey,
            s3SecretAccessKey: $secretKey,
            s3PathStyleEndpoint: $pathStyle,
            duckDbBinary: $duckDbBinary,
            commandTimeoutSeconds: self::positiveInt(
                self::acceptanceEnvironment('COMMAND_TIMEOUT_SECONDS', 'ARCHIVE_COMMAND_TIMEOUT_SECONDS', '120'),
                'Archive command timeout',
            ),
        );
    }

    /** @return array<string, mixed> */
    public function mysqlAdminParameters(): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => $this->mysqlHost,
            'port' => $this->mysqlPort,
            'user' => $this->mysqlUsername,
            'password' => $this->mysqlPassword,
            'charset' => 'utf8mb4',
        ];
    }

    /** @return array<string, mixed> */
    public function mysqlSettings(string $database): array
    {
        return [
            'driver' => 'pdo_mysql',
            'host' => $this->mysqlHost,
            'port' => $this->mysqlPort,
            'database' => $database,
            'username' => $this->mysqlUsername,
            'password' => $this->mysqlPassword,
            'charset' => 'utf8mb4',
        ];
    }

    /** @return array<string, mixed> */
    public function s3Settings(): array
    {
        return [
            'endpoint' => $this->s3Endpoint,
            'region' => $this->s3Region,
            'bucket' => $this->s3Bucket,
            'access_key_id' => $this->s3AccessKeyId,
            'secret_access_key' => $this->s3SecretAccessKey,
            'path_style_endpoint' => $this->s3PathStyleEndpoint,
        ];
    }

    /** @return array<string, mixed> */
    public function s3ClientSettings(): array
    {
        return [
            'version' => 'latest',
            'region' => $this->s3Region === '' ? 'auto' : $this->s3Region,
            'endpoint' => $this->s3Endpoint,
            'use_path_style_endpoint' => $this->s3PathStyleEndpoint,
            'credentials' => new Credentials($this->s3AccessKeyId, $this->s3SecretAccessKey),
        ];
    }

    private static function acceptanceEnvironment(string $suffix, string $fallback, string $default): string
    {
        $value = self::environment('VERTOAD_ARCHIVE_COLD_QUERY_ACCEPTANCE_' . $suffix, '');

        return $value === '' ? self::environment($fallback, $default) : $value;
    }

    private static function environment(string $name, string $default): string
    {
        $value = getenv($name);

        return $value === false ? $default : trim((string) $value);
    }

    private static function positiveInt(string $value, string $label): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) {
            throw new \RuntimeException($label . ' must be a positive integer.');
        }

        return (int) $value;
    }
}

final class ArchiveColdQueryAcceptanceHarness
{
    private const DATABASE_PATTERN = '/^vertoad_archive_acceptance_[a-f0-9]{16}$/D';
    private const PREFIX_PATTERN = '~^acceptance/archive-cold-query/[a-f0-9]{16}$~D';

    private ?Connection $mysqlAdmin = null;
    private ?Connection $connection = null;
    private ?S3Client $s3Client = null;
    private ?ArchiveColdQueryAcceptanceStorage $storage = null;
    private ?ArchiveRepositoryInterface $repository = null;
    private ?ArchiveParquetJob $archiveJob = null;
    private ?ColdQueryService $coldQueryService = null;
    private ?DuckDbColdQueryJob $coldQueryJob = null;
    private bool $databaseCreated = false;
    private bool $objectPrefixOwned = false;
    private bool $cleaned = false;
    /** @var array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}|null */
    private ?array $cleanupEvidence = null;
    /** @var list<array{event_id:string,event_type:string}> */
    private array $expectedRows = [];

    private function __construct(
        private readonly string $rootPath,
        private readonly ArchiveColdQueryAcceptanceConfig $config,
        private readonly string $runId,
        private readonly string $databaseName,
        private readonly string $objectPrefix,
        private readonly string $workspace,
        private string $mysqlVersion = '',
        private string $duckDbVersion = '',
    ) {
    }

    public static function boot(string $rootPath): self
    {
        EnvironmentLoader::load($rootPath);
        $config = ArchiveColdQueryAcceptanceConfig::fromEnvironment();
        $runId = bin2hex(random_bytes(8));
        $harness = new self(
            rootPath: $rootPath,
            config: $config,
            runId: $runId,
            databaseName: 'vertoad_archive_acceptance_' . $runId,
            objectPrefix: 'acceptance/archive-cold-query/' . $runId,
            workspace: $rootPath . DIRECTORY_SEPARATOR . 'build' . DIRECTORY_SEPARATOR
                . 'archive-cold-query' . DIRECTORY_SEPARATOR . $runId,
        );

        try {
            $harness->initialize();

            return $harness;
        } catch (Throwable $exception) {
            try {
                $harness->cleanup();
            } catch (Throwable $cleanupException) {
                throw new \RuntimeException(
                    'Archive cold-query acceptance initialization and cleanup both failed.',
                    previous: $cleanupException,
                );
            }

            throw $exception;
        }
    }

    public function mysqlVersion(): string
    {
        return $this->mysqlVersion;
    }

    public function duckDbVersion(): string
    {
        return $this->duckDbVersion;
    }

    public function databaseName(): string
    {
        return $this->databaseName;
    }

    public function objectPrefix(): string
    {
        return $this->objectPrefix;
    }

    public function connection(): Connection
    {
        return $this->connection ?? throw new \LogicException('The acceptance database connection is unavailable.');
    }

    public function repository(): ArchiveRepositoryInterface
    {
        return $this->repository ?? throw new \LogicException('The archive repository is unavailable.');
    }

    public function archiveJob(): ArchiveParquetJob
    {
        return $this->archiveJob ?? throw new \LogicException('The archive Cron job is unavailable.');
    }

    public function coldQueryService(): ColdQueryService
    {
        return $this->coldQueryService ?? throw new \LogicException('The cold-query service is unavailable.');
    }

    public function coldQueryJob(): DuckDbColdQueryJob
    {
        return $this->coldQueryJob ?? throw new \LogicException('The cold-query Cron job is unavailable.');
    }

    public function migrationCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM phinxlog');
    }

    public function pendingEventCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM raw_events WHERE processed_at IS NULL');
    }

    public function processedEventCount(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM raw_events WHERE processed_at IS NOT NULL');
    }

    public function seedSecondBatch(): void
    {
        $this->seedBatch('batch-b', [
            ['click', '2026-06-08 10:11:00'],
            ['click', '2026-06-08 10:13:00'],
            ['impression', '2026-06-08 10:15:00'],
            ['video_start', '2026-06-08 11:11:00'],
        ]);
    }

    /** @return list<array{event_id:string,event_type:string}> */
    public function expectedRows(): array
    {
        $rows = $this->expectedRows;
        usort($rows, static fn (array $left, array $right): int => $left['event_id'] <=> $right['event_id']);

        return $rows;
    }

    /** @return list<string> */
    public function objectKeys(): array
    {
        return $this->listObjectKeys();
    }

    public function objectBody(string $objectKey): string
    {
        return $this->storage()->get($objectKey);
    }

    /** @return array{content_type:string,content_length:int} */
    public function objectMetadata(string $objectKey): array
    {
        $this->assertOwnedObjectKey($objectKey);
        $result = $this->s3()->headObject([
            'Bucket' => $this->config->s3Bucket,
            'Key' => $objectKey,
        ]);

        return [
            'content_type' => (string) ($result['ContentType'] ?? ''),
            'content_length' => (int) ($result['ContentLength'] ?? -1),
        ];
    }

    /** @return list<string> */
    public function workspaceEntries(): array
    {
        $entries = glob($this->workspace . DIRECTORY_SEPARATOR . '*') ?: [];
        sort($entries);

        return array_values($entries);
    }

    /**
     * @return array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int}
     */
    public function cleanup(): array
    {
        if ($this->cleaned) {
            return $this->cleanupEvidence ?? $this->emptyCleanupEvidence();
        }
        $this->cleaned = true;

        $objectsBefore = 0;
        $objectsDeleted = 0;
        $objectsAfter = 0;
        $databaseBefore = 0;
        $databaseAfter = 0;
        $workspaceBefore = is_dir($this->workspace) ? 1 : 0;
        $workspaceAfter = $workspaceBefore;
        $failure = null;

        try {
            if ($this->objectPrefixOwned && $this->s3Client !== null) {
                $keys = $this->listObjectKeys();
                $objectsBefore = count($keys);
                foreach ($keys as $key) {
                    $this->s3Client->deleteObject([
                        'Bucket' => $this->config->s3Bucket,
                        'Key' => $key,
                    ]);
                    ++$objectsDeleted;
                }
                for ($attempt = 0; $attempt < 20; ++$attempt) {
                    $objectsAfter = count($this->listObjectKeys());
                    if ($objectsAfter === 0) {
                        break;
                    }
                    usleep(100_000);
                }
            }
        } catch (Throwable $exception) {
            $failure = $exception;
        }

        try {
            $this->connection?->close();
            $this->connection = null;
            if ($this->mysqlAdmin !== null && $this->databaseCreated) {
                $databaseBefore = $this->databaseExists();
                $this->mysqlAdmin->executeStatement('DROP DATABASE `' . $this->databaseName . '`');
                $this->databaseCreated = false;
                $databaseAfter = $this->databaseExists();
            }
            $this->mysqlAdmin?->close();
            $this->mysqlAdmin = null;
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        try {
            $this->removeWorkspace();
            $workspaceAfter = is_dir($this->workspace) ? 1 : 0;
        } catch (Throwable $exception) {
            $failure ??= $exception;
        }

        $this->cleanupEvidence = [
            'objects_before' => $objectsBefore,
            'objects_deleted' => $objectsDeleted,
            'objects_after' => $objectsAfter,
            'database_before' => $databaseBefore,
            'database_after' => $databaseAfter,
            'workspace_before' => $workspaceBefore,
            'workspace_after' => $workspaceAfter,
        ];

        if ($failure !== null) {
            throw new \RuntimeException('Archive cold-query acceptance cleanup failed.', previous: $failure);
        }
        if ($objectsAfter !== 0 || $databaseAfter !== 0 || $workspaceAfter !== 0) {
            throw new \RuntimeException('Archive cold-query acceptance cleanup left isolated resources behind.');
        }

        return $this->cleanupEvidence;
    }

    private function initialize(): void
    {
        $this->assertIdentity();
        if (file_exists($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('The archive acceptance workspace unexpectedly already exists.');
        }
        if (!mkdir($this->workspace, 0700, true) && !is_dir($this->workspace)) {
            throw new \RuntimeException('Unable to create the archive acceptance workspace.');
        }

        $commandRunner = new ProcessArchiveCommandRunner();
        $version = $commandRunner->run([$this->config->duckDbBinary, '--version'], null, 15);
        if ($version->exitCode !== 0 || preg_match('/^v\d+\.\d+\.\d+\b/', trim($version->stdout)) !== 1) {
            throw new \RuntimeException('The configured DuckDB executable did not return a supported version.');
        }
        $this->duckDbVersion = trim($version->stdout);

        $this->mysqlAdmin = DriverManager::getConnection($this->config->mysqlAdminParameters());
        $this->mysqlVersion = (string) $this->mysqlAdmin->fetchOne('SELECT VERSION()');
        if (preg_match('/^8\./D', $this->mysqlVersion) !== 1) {
            throw new \RuntimeException('The archive cold-query acceptance runner requires MySQL 8.');
        }
        $this->mysqlAdmin->executeStatement(
            'CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
        );
        $this->databaseCreated = true;

        $databaseSettings = $this->config->mysqlSettings($this->databaseName);
        (new PhinxMigrationRunner($this->rootPath))->migrate($databaseSettings);
        $this->connection = (new ConnectionFactory())->create($databaseSettings);

        $this->s3Client = new S3Client($this->config->s3ClientSettings());
        if ($this->listObjectKeys() !== []) {
            throw new \RuntimeException('The generated archive acceptance object prefix was not empty.');
        }
        $this->objectPrefixOwned = true;

        $this->storage = new ArchiveColdQueryAcceptanceStorage(
            new S3ArchiveObjectStorage($this->config->s3Settings()),
            $this->objectPrefix,
        );
        $this->repository = new DatabaseArchiveRepository($this->connection);
        $this->archiveJob = new ArchiveParquetJob(new ArchiveJob(
            $this->repository,
            $this->objectPrefix . '/raw-events',
            new DuckDbCliArchiveWriter(
                $this->storage,
                $this->config->duckDbBinary,
                $this->workspace,
                $commandRunner,
                $this->config->commandTimeoutSeconds,
            ),
        ));
        $this->coldQueryService = new ColdQueryService(
            $this->repository,
            $this->objectPrefix . '/query-results',
            new DuckDbCliColdQueryRunner(
                $this->storage,
                $this->config->duckDbBinary,
                $this->workspace,
                $commandRunner,
                $this->config->commandTimeoutSeconds,
                maxScannedObjects: 100,
                maxResultBytes: 1_048_576,
            ),
        );
        $this->coldQueryJob = new DuckDbColdQueryJob($this->coldQueryService);

        $this->seedBatch('batch-a', [
            ['click', '2026-06-08 10:01:00'],
            ['click', '2026-06-08 10:03:00'],
            ['impression', '2026-06-08 10:05:00'],
            ['video_start', '2026-06-08 11:01:00'],
        ]);
    }

    /** @param list<array{0:string,1:string}> $events */
    private function seedBatch(string $batch, array $events): void
    {
        foreach ($events as $index => [$eventType, $occurredAt]) {
            $eventId = sprintf('archive-%s-%s-%d', $this->runId, $batch, $index + 1);
            $this->connection()->insert('raw_events', [
                'event_uuid' => $eventId,
                'organization_id' => null,
                'site_id' => null,
                'ad_slot_id' => null,
                'campaign_id' => null,
                'creative_id' => null,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'received_at' => $occurredAt,
                'request_ip' => null,
                'user_agent' => 'VertoAD archive cold-query acceptance runner',
                'payload_json' => json_encode([
                    'request_id' => 'request-' . $eventId,
                    'campaign_reference' => 'campaign-acceptance',
                    'cost_points' => $eventType === 'click' ? 20 : 0,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
                'processed_at' => null,
            ]);
            $this->expectedRows[] = ['event_id' => $eventId, 'event_type' => $eventType];
        }
    }

    /** @return list<string> */
    private function listObjectKeys(): array
    {
        if ($this->s3Client === null) {
            return [];
        }

        $keys = [];
        $continuationToken = null;
        for ($page = 0; $page < 100; ++$page) {
            $request = [
                'Bucket' => $this->config->s3Bucket,
                'Prefix' => $this->objectPrefix . '/',
                'MaxKeys' => 1000,
            ];
            if ($continuationToken !== null) {
                $request['ContinuationToken'] = $continuationToken;
            }
            $result = $this->s3Client->listObjectsV2($request);
            foreach ($result['Contents'] ?? [] as $object) {
                $key = trim((string) ($object['Key'] ?? ''));
                if ($key !== '') {
                    $this->assertOwnedObjectKey($key);
                    $keys[] = $key;
                }
            }
            if (!(bool) ($result['IsTruncated'] ?? false)) {
                sort($keys);

                return array_values(array_unique($keys));
            }
            $continuationToken = trim((string) ($result['NextContinuationToken'] ?? ''));
            if ($continuationToken === '') {
                throw new \RuntimeException('S3-compatible object listing omitted its continuation token.');
            }
        }

        throw new \RuntimeException('S3-compatible object listing exceeded the acceptance page limit.');
    }

    private function s3(): S3Client
    {
        return $this->s3Client ?? throw new \LogicException('The acceptance S3 client is unavailable.');
    }

    private function storage(): ArchiveColdQueryAcceptanceStorage
    {
        return $this->storage ?? throw new \LogicException('The acceptance archive storage is unavailable.');
    }

    private function databaseExists(): int
    {
        if ($this->mysqlAdmin === null) {
            return 0;
        }

        return (int) $this->mysqlAdmin->fetchOne(
            'SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = ?',
            [$this->databaseName],
        );
    }

    private function assertIdentity(): void
    {
        if (
            preg_match(self::DATABASE_PATTERN, $this->databaseName) !== 1
            || preg_match(self::PREFIX_PATTERN, $this->objectPrefix) !== 1
            || basename($this->workspace) !== $this->runId
        ) {
            throw new \LogicException('Unsafe archive cold-query acceptance resource identity.');
        }
    }

    private function assertOwnedObjectKey(string $objectKey): void
    {
        if (!str_starts_with($objectKey, $this->objectPrefix . '/')) {
            throw new \LogicException('Refusing to access an object outside the acceptance prefix.');
        }
    }

    private function removeWorkspace(): void
    {
        if (!file_exists($this->workspace) && !is_link($this->workspace)) {
            return;
        }
        $this->assertIdentity();
        if (!is_dir($this->workspace) || is_link($this->workspace)) {
            throw new \RuntimeException('Refusing to remove an invalid archive acceptance workspace.');
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->workspace, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            if ($item->isLink()) {
                throw new \RuntimeException('Refusing to follow a link during archive acceptance cleanup.');
            }
            $removed = $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            if (!$removed) {
                throw new \RuntimeException('Unable to remove an archive acceptance workspace entry.');
            }
        }
        if (!rmdir($this->workspace)) {
            throw new \RuntimeException('Unable to remove the archive acceptance workspace.');
        }
    }

    /** @return array{objects_before:int,objects_deleted:int,objects_after:int,database_before:int,database_after:int,workspace_before:int,workspace_after:int} */
    private function emptyCleanupEvidence(): array
    {
        return [
            'objects_before' => 0,
            'objects_deleted' => 0,
            'objects_after' => 0,
            'database_before' => 0,
            'database_after' => 0,
            'workspace_before' => 0,
            'workspace_after' => 0,
        ];
    }
}

final class ArchiveColdQueryAcceptanceStorage implements ArchiveObjectStorageInterface
{
    /** @var array<string, true> */
    private array $writtenObjectKeys = [];

    public function __construct(
        private readonly ArchiveObjectStorageInterface $inner,
        private readonly string $ownedPrefix,
    ) {
    }

    public function put(string $objectKey, string $body, string $contentType): void
    {
        $this->assertOwned($objectKey);
        if ($contentType === '') {
            throw new \InvalidArgumentException('Archive acceptance object content type is required.');
        }
        $this->writtenObjectKeys[$objectKey] = true;
        $this->inner->put($objectKey, $body, $contentType);
    }

    public function get(string $objectKey): string
    {
        $this->assertOwned($objectKey);

        return $this->inner->get($objectKey);
    }

    /** @return list<string> */
    public function writtenObjectKeys(): array
    {
        $keys = array_keys($this->writtenObjectKeys);
        sort($keys);

        return $keys;
    }

    private function assertOwned(string $objectKey): void
    {
        if (
            str_contains($objectKey, '://')
            || !str_starts_with($objectKey, $this->ownedPrefix . '/')
        ) {
            throw new \LogicException('Archive acceptance storage rejected an object outside its unique prefix.');
        }
    }
}
