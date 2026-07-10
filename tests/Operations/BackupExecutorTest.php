<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\Backup\BackupExecutor;
use VertoAD\Service\Operations\Backup\BackupInventory;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;
use VertoAD\Service\Operations\Backup\MysqlBackupRunnerInterface;

final class BackupExecutorTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-backup-test-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        $this->remove($this->tempDirectory);
    }

    public function testCreatesEncryptedStoragePayloadInventoryAndManifest(): void
    {
        [$executor, $jobs, $storage, $mysql, $audit] = $this->executor();
        $jobs->save($this->queuedBackup());

        $result = $executor->executeNextBackup();
        $stored = $jobs->find('backup_test_id');
        $manifest = json_decode($storage->objects['backups/backup_test_id/manifest.json'], true, flags: JSON_THROW_ON_ERROR);
        $config = json_decode($storage->objects['backups/backup_test_id/configuration.json'], true, flags: JSON_THROW_ON_ERROR);

        self::assertSame('completed', $result->status);
        self::assertSame(3, $result->objectCount);
        self::assertSame('completed', $stored?->status);
        self::assertSame(hash('sha256', $mysql->dumpBody), $stored?->mysqlSha256);
        self::assertSame('vertoad-backup-manifest-v1', $manifest['schema']);
        self::assertSame(
            hash('sha256', $storage->objects['backups/backup_test_id/configuration.json']),
            $manifest['configuration']['sha256'],
        );
        self::assertSame([
            'assets/image.png',
            'proofs/paid.pdf',
            's3://archive/raw.parquet',
        ], array_column($manifest['objects'], 'source_object_key'));
        self::assertSame('vertoad-config-backup-v1', $config['schema']);
        self::assertSame('serving.geo_provider', $config['system_config_versions'][0]['config_key']);
        self::assertSame(['include_builtins' => true], $config['system_config_versions'][0]['value']);
        self::assertSame('operations.backup.completed', $audit->entries[0]->action);
        self::assertDirectoryDoesNotExist($this->tempDirectory . DIRECTORY_SEPARATOR . 'backup_test_id');
    }

    public function testRestoresVerifiedMysqlAndEveryCopiedObjectAndWritesEvidence(): void
    {
        [$executor, $jobs, $storage, $mysql, $audit] = $this->executor();
        $jobs->save($this->queuedBackup());
        self::assertSame('completed', $executor->executeNextBackup()->status);
        $jobs->save($this->queuedRestore());
        $storage->objects['assets/image.png'] = 'changed';
        $storage->objects['proofs/paid.pdf'] = 'changed';
        $storage->objects['s3://archive/raw.parquet'] = 'changed';

        $result = $executor->executeNextRestore();
        $restored = $jobs->find('restore_test_id');

        self::assertSame('completed', $result->status);
        self::assertSame($mysql->dumpBody, $mysql->restoredBody);
        self::assertSame('asset-body', $storage->objects['assets/image.png']);
        self::assertSame('proof-body', $storage->objects['proofs/paid.pdf']);
        self::assertSame('parquet-body', $storage->objects['s3://archive/raw.parquet']);
        self::assertSame('completed', $jobs->find('backup_test_id')?->status);
        self::assertSame('completed', $restored?->status);
        self::assertSame('backups/restore-evidence/restore_test_id.json', $restored?->evidenceObjectKey);
        self::assertSame('operations.backup.restore_completed', $audit->entries[1]->action);
        self::assertStringContainsString('vertoad-restore-evidence-v1', $storage->objects['backups/restore-evidence/restore_test_id.json']);
    }

    public function testReturnsIdleWhenNoJobsAreQueued(): void
    {
        [$executor] = $this->executor();

        self::assertSame('idle', $executor->executeNextBackup()->status);
        self::assertSame('idle', $executor->executeNextRestore()->status);
    }

    public function testMarksBackupFailedWhenDumpOrCriticalObjectIsInvalid(): void
    {
        [$executor, $jobs, $storage, $mysql, $audit] = $this->executor();
        $jobs->save($this->queuedBackup());
        $mysql->dumpBody = '';

        $empty = $executor->executeNextBackup();
        self::assertSame('failed', $empty->status);
        self::assertStringContainsString('measure', (string) $empty->errorMessage);
        self::assertSame('operations.backup.failed', $audit->entries[0]->action);

        [$missingExecutor, $missingJobs, $missingStorage] = $this->executor();
        $missingJobs->save($this->queuedBackup());
        unset($missingStorage->objects['assets/image.png']);
        $missing = $missingExecutor->executeNextBackup();
        self::assertSame('failed', $missing->status);
        self::assertStringContainsString('Critical object is missing', (string) $missing->errorMessage);
    }

    #[DataProvider('invalidManifestProvider')]
    public function testRestorePreflightRejectsInvalidManifests(array|string $manifest, string $expected): void
    {
        [$executor, $jobs, $storage] = $this->executor();
        $manifestJson = is_string($manifest) ? $manifest : json_encode($manifest, JSON_THROW_ON_ERROR);
        $source = $this->completedBackup($manifestJson);
        $jobs->save($source);
        $jobs->save($this->queuedRestore($manifestJson));
        $storage->objects[(string) $source->manifestObjectKey] = $manifestJson;

        $result = $executor->executeNextRestore();

        self::assertSame('failed', $result->status);
        self::assertStringContainsString($expected, (string) $result->errorMessage);
    }

    /** @return iterable<string, array{array<string,mixed>|string,string}> */
    public static function invalidManifestProvider(): iterable
    {
        yield 'malformed json' => ['{', 'Syntax error'];
        yield 'wrong schema' => [['schema' => 'other'], 'schema is invalid'];
        yield 'wrong id' => [[
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'other',
        ], 'identifier does not match'];
        yield 'incomplete' => [[
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'backup_test_id',
        ], 'payload is incomplete'];
        yield 'invalid mysql' => [[
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 7, 'sha256' => 'bad', 'byte_count' => 1],
            'configuration' => ['object_key' => 'config.json', 'sha256' => str_repeat('b', 64), 'byte_count' => 1],
            'objects' => [],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'MySQL payload is invalid'];
        yield 'invalid configuration' => [[
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 'mysql.sql', 'sha256' => str_repeat('a', 64), 'byte_count' => 1],
            'configuration' => ['object_key' => '', 'sha256' => 'bad', 'byte_count' => 0],
            'objects' => [],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'configuration payload is invalid'];
        yield 'invalid object' => [[
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 'mysql.sql', 'sha256' => str_repeat('a', 64), 'byte_count' => 1],
            'configuration' => ['object_key' => 'config.json', 'sha256' => str_repeat('b', 64), 'byte_count' => 1],
            'objects' => [[
                'source_object_key' => '',
                'backup_object_key' => 'copy',
                'sha256' => str_repeat('c', 64),
                'byte_count' => -1,
            ]],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'object inventory is invalid'];
    }

    public function testRestoreRejectsEnvironmentMissingSourceObjectsAndChecksumMismatch(): void
    {
        [$forbidden, $jobs] = $this->executor(restoreAllowedEnvironments: ['testing']);
        $jobs->save($this->completedBackup());
        $jobs->save($this->queuedRestore());
        self::assertStringContainsString('target environment', (string) $forbidden->executeNextRestore()->errorMessage);

        [$missingSource, $missingJobs] = $this->executor();
        $missingJobs->save($this->queuedRestore());
        self::assertStringContainsString('source backup', (string) $missingSource->executeNextRestore()->errorMessage);

        [$missingObject, $objectJobs, $objectStorage] = $this->executor();
        $this->installValidRestoreFixture($objectJobs, $objectStorage);
        unset($objectStorage->objects['backups/backup_test_id/mysql.sql']);
        self::assertStringContainsString('could not find backup object', (string) $missingObject->executeNextRestore()->errorMessage);

        [$missingCopy, $copyJobs, $copyStorage] = $this->executor();
        $this->installValidRestoreFixture($copyJobs, $copyStorage, [[
            'source_object_key' => 'assets/image.png',
            'backup_object_key' => 'backups/backup_test_id/objects/image.png',
            'sha256' => hash('sha256', 'asset-body'),
            'byte_count' => 10,
        ]]);
        self::assertStringContainsString('could not find copied object', (string) $missingCopy->executeNextRestore()->errorMessage);

        [$checksum, $checksumJobs, $checksumStorage] = $this->executor();
        $this->installValidRestoreFixture($checksumJobs, $checksumStorage);
        $originalDump = $checksumStorage->objects['backups/backup_test_id/mysql.sql'];
        $checksumStorage->objects['backups/backup_test_id/mysql.sql'] = 'X' . substr($originalDump, 1);
        self::assertStringContainsString('checksum verification failed', (string) $checksum->executeNextRestore()->errorMessage);
    }

    public function testBackupAndRestoreRejectSizeAndConfigurationIntegrityFailures(): void
    {
        [$copyBackup, $copyBackupJobs, $copyBackupStorage] = $this->executor();
        $copyBackupStorage->corruptEveryCopy = true;
        $copyBackupJobs->save($this->queuedBackup());
        self::assertStringContainsString('backup size verification failed', (string) $copyBackup->executeNextBackup()->errorMessage);

        [$requiredSize, $requiredSizeJobs, $requiredSizeStorage] = $this->executor();
        $this->installValidRestoreFixture($requiredSizeJobs, $requiredSizeStorage);
        $requiredSizeStorage->sizeOverrides['backups/backup_test_id/mysql.sql'] = 999;
        self::assertStringContainsString('backup object size verification failed', (string) $requiredSize->executeNextRestore()->errorMessage);

        [$copiedSize, $copiedSizeJobs, $copiedSizeStorage] = $this->executor();
        $copiedObject = 'backups/backup_test_id/objects/image.png';
        $copiedSizeStorage->objects[$copiedObject] = 'asset-body';
        $this->installValidRestoreFixture($copiedSizeJobs, $copiedSizeStorage, [[
            'source_object_key' => 'assets/image.png',
            'backup_object_key' => $copiedObject,
            'sha256' => hash('sha256', 'asset-body'),
            'byte_count' => strlen('asset-body'),
        ]]);
        $copiedSizeStorage->sizeOverrides[$copiedObject] = 999;
        self::assertStringContainsString('copied object size verification failed', (string) $copiedSize->executeNextRestore()->errorMessage);

        [$configHash, $configHashJobs, $configHashStorage] = $this->executor();
        $this->installValidRestoreFixture($configHashJobs, $configHashStorage);
        $configHashStorage->objects['backups/backup_test_id/configuration.json'] = '{"schema":"tampered"}';
        $configHashStorage->sizeOverrides['backups/backup_test_id/configuration.json'] = strlen($this->validConfigJson());
        self::assertStringContainsString('Configuration backup checksum', (string) $configHash->executeNextRestore()->errorMessage);

        [$configSchema, $configSchemaJobs, $configSchemaStorage] = $this->executor();
        $this->installValidRestoreFixture($configSchemaJobs, $configSchemaStorage, configJson: '{"schema":"other"}');
        self::assertStringContainsString('Configuration backup schema', (string) $configSchema->executeNextRestore()->errorMessage);

        [$restoredSize, $restoredSizeJobs, $restoredSizeStorage] = $this->executor();
        $restoredObject = 'backups/backup_test_id/objects/image.png';
        $restoredSizeStorage->objects[$restoredObject] = 'asset-body';
        $this->installValidRestoreFixture($restoredSizeJobs, $restoredSizeStorage, [[
            'source_object_key' => 'assets/image.png',
            'backup_object_key' => $restoredObject,
            'sha256' => hash('sha256', 'asset-body'),
            'byte_count' => strlen('asset-body'),
        ]]);
        $restoredSizeStorage->corruptEveryCopy = true;
        self::assertStringContainsString('Restored object size verification failed', (string) $restoredSize->executeNextRestore()->errorMessage);
        self::assertSame('completed', $restoredSizeJobs->find('backup_test_id')?->status);
        self::assertSame('failed', $restoredSizeJobs->find('restore_test_id')?->status);
    }

    public function testBackupRejectsEveryUploadedPayloadIntegrityFailure(): void
    {
        $cases = [
            ['sizeOverrides', 'backups/backup_test_id/mysql.sql', 999, 'MySQL backup upload size'],
            ['shaOverrides', 'backups/backup_test_id/mysql.sql', str_repeat('0', 64), 'MySQL backup upload checksum'],
            ['sizeOverrides', 'backups/backup_test_id/configuration.json', 999, 'Configuration backup upload size'],
            ['shaOverrides', 'backups/backup_test_id/configuration.json', str_repeat('0', 64), 'Configuration backup upload checksum'],
            ['sizeOverrides', 'backups/backup_test_id/manifest.json', 999, 'manifest upload size'],
            ['shaOverrides', 'backups/backup_test_id/manifest.json', str_repeat('0', 64), 'manifest upload checksum'],
        ];
        foreach ($cases as [$property, $objectKey, $override, $expected]) {
            [$executor, $jobs, $storage] = $this->executor();
            $storage->{$property}[$objectKey] = $override;
            $jobs->save($this->queuedBackup());

            self::assertStringContainsString($expected, (string) $executor->executeNextBackup()->errorMessage);
        }

        [$executor, $jobs, $storage] = $this->executor();
        $storage->corruptCopiesWithoutChangingSize = true;
        $jobs->save($this->queuedBackup());
        self::assertStringContainsString(
            'Critical object backup checksum',
            (string) $executor->executeNextBackup()->errorMessage,
        );
    }

    public function testRestoreRejectsManifestAndObjectTamperingWithUnchangedSizes(): void
    {
        [$missingHash, $missingHashJobs] = $this->executor();
        $missingHashJobs->save($this->completedBackup());
        $missingHashJobs->save($this->queuedRestore());
        self::assertStringContainsString(
            'Trusted backup manifest checksum',
            (string) $missingHash->executeNextRestore()->errorMessage,
        );

        [$manifestTamper, $manifestJobs, $manifestStorage] = $this->executor();
        $this->installValidRestoreFixture($manifestJobs, $manifestStorage);
        $manifestStorage->objects['backups/backup_test_id/manifest.json'] .= ' ';
        self::assertStringContainsString(
            'manifest checksum verification',
            (string) $manifestTamper->executeNextRestore()->errorMessage,
        );

        [$metadataTamper, $metadataJobs, $metadataStorage] = $this->executor();
        $this->installValidRestoreFixture($metadataJobs, $metadataStorage);
        $source = $metadataJobs->find('backup_test_id');
        self::assertInstanceOf(BackupJob::class, $source);
        $metadataJobs->save($this->withMysqlSha256($source, str_repeat('f', 64)));
        self::assertStringContainsString(
            'trusted job metadata',
            (string) $metadataTamper->executeNextRestore()->errorMessage,
        );

        [$copiedTamper, $copiedJobs, $copiedStorage] = $this->executor();
        $copiedObject = 'backups/backup_test_id/objects/image.png';
        $copiedStorage->objects[$copiedObject] = 'asset-body';
        $this->installValidRestoreFixture($copiedJobs, $copiedStorage, [[
            'source_object_key' => 'assets/image.png',
            'backup_object_key' => $copiedObject,
            'sha256' => hash('sha256', 'asset-body'),
            'byte_count' => strlen('asset-body'),
        ]]);
        $copiedStorage->objects[$copiedObject] = 'Asset-body';
        self::assertStringContainsString(
            'copied object checksum',
            (string) $copiedTamper->executeNextRestore()->errorMessage,
        );

        [$restoredTamper, $restoredJobs, $restoredStorage] = $this->executor();
        $restoredObject = 'backups/backup_test_id/objects/image.png';
        $restoredStorage->objects[$restoredObject] = 'asset-body';
        $this->installValidRestoreFixture($restoredJobs, $restoredStorage, [[
            'source_object_key' => 'assets/image.png',
            'backup_object_key' => $restoredObject,
            'sha256' => hash('sha256', 'asset-body'),
            'byte_count' => strlen('asset-body'),
        ]]);
        $restoredStorage->corruptCopiesWithoutChangingSize = true;
        self::assertStringContainsString(
            'Restored object checksum',
            (string) $restoredTamper->executeNextRestore()->errorMessage,
        );
    }

    public function testBackupRejectsInvalidPathsAndCleansRemovedOrNestedDirectories(): void
    {
        [$blankBase, $blankBaseJobs] = $this->executor(baseObjectKey: ' ');
        $blankBaseJobs->save($this->queuedBackup());
        self::assertStringContainsString('base object key', (string) $blankBase->executeNextBackup()->errorMessage);

        [$blankTemp, $blankTempJobs] = $this->executor(tempDirectory: ' ');
        $blankTempJobs->save($this->queuedBackup());
        self::assertStringContainsString('temporary directory is required', (string) $blankTemp->executeNextBackup()->errorMessage);

        $blockedRoot = $this->tempDirectory . '-blocked';
        file_put_contents($blockedRoot, 'blocked');
        [$blockedTemp, $blockedJobs] = $this->executor(tempDirectory: $blockedRoot);
        $blockedJobs->save($this->queuedBackup());
        self::assertStringContainsString('create backup temporary directory', (string) $blockedTemp->executeNextBackup()->errorMessage);
        @unlink($blockedRoot);

        [$removed, $removedJobs, , $removedMysql] = $this->executor();
        $removedMysql->removeDirectoryDuringDump = true;
        $removedJobs->save($this->queuedBackup());
        self::assertStringContainsString('removed dump directory', (string) $removed->executeNextBackup()->errorMessage);

        [$nested, $nestedJobs, , $nestedMysql] = $this->executor();
        $nestedMysql->createNestedDirectory = true;
        $nestedJobs->save($this->queuedBackup());
        self::assertSame('completed', $nested->executeNextBackup()->status);
        self::assertDirectoryDoesNotExist($this->tempDirectory . DIRECTORY_SEPARATOR . 'backup_test_id');

        [$invalidId, $invalidIdJobs] = $this->executor();
        $invalidIdJobs->save($this->job('../escape', 'backup', null, 'queued'));
        self::assertStringContainsString('identifier is invalid', (string) $invalidId->executeNextBackup()->errorMessage);
    }

    /**
     * @param list<string> $restoreAllowedEnvironments
     * @return array{BackupExecutor,InMemoryBackupJobRepository,ExecutorMemoryStorage,ExecutorMysqlRunner,OperationAuditRepository}
     */
    private function executor(
        array $restoreAllowedEnvironments = ['staging'],
        string $baseObjectKey = 'backups',
        ?string $tempDirectory = null,
    ): array
    {
        $jobs = new InMemoryBackupJobRepository();
        $storage = new ExecutorMemoryStorage([
            'assets/image.png' => 'asset-body',
            'proofs/paid.pdf' => 'proof-body',
            's3://archive/raw.parquet' => 'parquet-body',
        ]);
        $mysql = new ExecutorMysqlRunner();
        $audit = new OperationAuditRepository();
        $executor = new BackupExecutor(
            $jobs,
            $mysql,
            $storage,
            new BackupInventory($this->connection()),
            new AuditLogService($audit),
            $baseObjectKey,
            $tempDirectory ?? $this->tempDirectory,
            $restoreAllowedEnvironments,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );

        return [$executor, $jobs, $storage, $mysql, $audit];
    }

    private function connection(): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $connection->executeStatement('CREATE TABLE system_config_versions (version_id VARCHAR(80), config_key VARCHAR(160), version INTEGER, value_json TEXT, created_by_user_id INTEGER, created_at VARCHAR(32))');
        $connection->insert('system_config_versions', [
            'version_id' => 'cfg-1',
            'config_key' => 'serving.geo_provider',
            'version' => 1,
            'value_json' => '{"include_builtins":true}',
            'created_by_user_id' => 1,
            'created_at' => '2026-07-10 09:00:00',
        ]);
        $connection->executeStatement('CREATE TABLE creative_assets (object_key VARCHAR(512))');
        $connection->insert('creative_assets', ['object_key' => 'assets/image.png']);
        $connection->executeStatement('CREATE TABLE withdrawal_proofs (object_key VARCHAR(512), status VARCHAR(32))');
        $connection->insert('withdrawal_proofs', ['object_key' => 'proofs/paid.pdf', 'status' => 'confirmed']);
        $connection->insert('withdrawal_proofs', ['object_key' => 'proofs/pending.pdf', 'status' => 'pending_upload']);
        $connection->executeStatement('CREATE TABLE archive_manifests (status VARCHAR(32), partitions_json TEXT)');
        $connection->insert('archive_manifests', [
            'status' => 'completed',
            'partitions_json' => '[{"object_key":"s3://archive/raw.parquet"}]',
        ]);
        $connection->insert('archive_manifests', [
            'status' => 'completed',
            'partitions_json' => 'null',
        ]);

        return $connection;
    }

    private function queuedBackup(): BackupJob
    {
        return $this->job('backup_test_id', 'backup', null, 'queued');
    }

    private function completedBackup(?string $manifestJson = null): BackupJob
    {
        return $this->job(
            'backup_test_id',
            'backup',
            null,
            'completed',
            manifestSha256: $manifestJson === null ? null : hash('sha256', $manifestJson),
        );
    }

    private function queuedRestore(?string $manifestJson = null): BackupJob
    {
        return $this->job(
            'restore_test_id',
            'restore',
            'backup_test_id',
            'queued',
            manifestSha256: $manifestJson === null ? null : hash('sha256', $manifestJson),
        );
    }

    private function job(
        string $id,
        string $type,
        ?string $sourceId,
        string $status,
        ?string $manifestSha256 = null,
        int $objectCount = 0,
        int $byteCount = 0,
    ): BackupJob {
        return new BackupJob(
            jobId: $id,
            jobType: $type,
            sourceBackupId: $sourceId,
            status: $status,
            requestedByUserId: 7,
            requestId: 'req-' . $id,
            environment: 'staging',
            reason: $type === 'restore' ? 'Scheduled staging restore drill' : null,
            manifestObjectKey: $status === 'completed' || $type === 'restore' ? 'backups/backup_test_id/manifest.json' : null,
            manifestSha256: $status === 'completed' || $type === 'restore' ? $manifestSha256 : null,
            mysqlObjectKey: $status === 'completed' || $type === 'restore' ? 'backups/backup_test_id/mysql.sql' : null,
            mysqlSha256: $status === 'completed' || $type === 'restore' ? hash('sha256', 'CREATE TABLE example (id INT);') : null,
            configObjectKey: $status === 'completed' || $type === 'restore' ? 'backups/backup_test_id/configuration.json' : null,
            evidenceObjectKey: null,
            objectCount: $objectCount,
            byteCount: $byteCount,
            errorMessage: null,
            createdAt: new DateTimeImmutable('2026-07-10T09:00:00Z'),
            startedAt: $status === 'queued' ? null : new DateTimeImmutable('2026-07-10T09:01:00Z'),
            completedAt: $status === 'completed' ? new DateTimeImmutable('2026-07-10T09:02:00Z') : null,
        );
    }

    /** @param list<array{source_object_key:string,backup_object_key:string,sha256:string,byte_count:int}> $objects */
    private function installValidRestoreFixture(
        InMemoryBackupJobRepository $jobs,
        ExecutorMemoryStorage $storage,
        array $objects = [],
        ?string $configJson = null,
    ): void {
        $manifestJson = $this->installValidManifest($storage, $objects, $configJson);
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $jobs->save($this->job(
            'backup_test_id',
            'backup',
            null,
            'completed',
            hash('sha256', $manifestJson),
            count($objects),
            (int) $manifest['payload_byte_count'],
        ));
        $jobs->save($this->job(
            'restore_test_id',
            'restore',
            'backup_test_id',
            'queued',
            hash('sha256', $manifestJson),
        ));
    }

    /** @param list<array{source_object_key:string,backup_object_key:string,sha256:string,byte_count:int}> $objects */
    private function installValidManifest(
        ExecutorMemoryStorage $storage,
        array $objects = [],
        ?string $configJson = null,
    ): string {
        $mysql = 'CREATE TABLE example (id INT);';
        $configJson ??= $this->validConfigJson();
        $storage->objects['backups/backup_test_id/mysql.sql'] = $mysql;
        $storage->objects['backups/backup_test_id/configuration.json'] = $configJson;
        $manifestJson = json_encode([
            'schema' => 'vertoad-backup-manifest-v1',
            'backup_id' => 'backup_test_id',
            'environment' => 'staging',
            'mysql' => [
                'object_key' => 'backups/backup_test_id/mysql.sql',
                'sha256' => hash('sha256', $mysql),
                'byte_count' => strlen($mysql),
            ],
            'configuration' => [
                'object_key' => 'backups/backup_test_id/configuration.json',
                'sha256' => hash('sha256', $configJson),
                'byte_count' => strlen($configJson),
            ],
            'objects' => $objects,
            'payload_byte_count' => strlen($mysql)
                + strlen($configJson)
                + array_sum(array_column($objects, 'byte_count')),
        ], JSON_THROW_ON_ERROR);
        $storage->objects['backups/backup_test_id/manifest.json'] = $manifestJson;

        return $manifestJson;
    }

    private function validConfigJson(): string
    {
        return '{"schema":"vertoad-config-backup-v1","system_config_versions":[]}';
    }

    private function withMysqlSha256(BackupJob $job, string $mysqlSha256): BackupJob
    {
        return new BackupJob(
            jobId: $job->jobId,
            jobType: $job->jobType,
            sourceBackupId: $job->sourceBackupId,
            status: $job->status,
            requestedByUserId: $job->requestedByUserId,
            requestId: $job->requestId,
            environment: $job->environment,
            reason: $job->reason,
            manifestObjectKey: $job->manifestObjectKey,
            manifestSha256: $job->manifestSha256,
            mysqlObjectKey: $job->mysqlObjectKey,
            mysqlSha256: $mysqlSha256,
            configObjectKey: $job->configObjectKey,
            evidenceObjectKey: $job->evidenceObjectKey,
            objectCount: $job->objectCount,
            byteCount: $job->byteCount,
            errorMessage: $job->errorMessage,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $job->completedAt,
        );
    }

    private function remove(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (array_diff(scandir($directory) ?: [], ['.', '..']) as $item) {
            $path = $directory . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->remove($path) : unlink($path);
        }
        rmdir($directory);
    }
}

final class ExecutorMysqlRunner implements MysqlBackupRunnerInterface
{
    public string $dumpBody = 'CREATE TABLE example (id INT);';
    public ?string $restoredBody = null;
    public bool $failRestore = false;
    public bool $removeDirectoryDuringDump = false;
    public bool $createNestedDirectory = false;

    public function dump(string $destinationPath): void
    {
        if ($this->removeDirectoryDuringDump) {
            @rmdir(dirname($destinationPath));
            throw new RuntimeException('removed dump directory');
        }
        if ($this->createNestedDirectory) {
            $nested = dirname($destinationPath) . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'child';
            mkdir($nested, 0700, true);
            file_put_contents($nested . DIRECTORY_SEPARATOR . 'probe.txt', 'probe');
        }
        file_put_contents($destinationPath, $this->dumpBody);
    }

    public function restore(string $sourcePath): void
    {
        if ($this->failRestore) {
            throw new RuntimeException('restore failed');
        }
        $this->restoredBody = (string) file_get_contents($sourcePath);
    }
}

final class ExecutorMemoryStorage implements BackupObjectStorageInterface
{
    public bool $corruptEveryCopy = false;
    public bool $corruptCopiesWithoutChangingSize = false;
    /** @var array<string, int> */
    public array $sizeOverrides = [];
    /** @var array<string, string> */
    public array $shaOverrides = [];
    /** @param array<string, string> $objects */
    public function __construct(public array $objects = [])
    {
    }

    public function putFile(string $objectKey, string $localPath, string $contentType): void
    {
        $this->objects[$objectKey] = (string) file_get_contents($localPath);
    }

    public function putString(string $objectKey, string $body, string $contentType): void
    {
        $this->objects[$objectKey] = $body;
    }

    public function getFile(string $objectKey, string $localPath): void
    {
        file_put_contents($localPath, $this->objects[$objectKey]);
    }

    public function readString(string $objectKey): string
    {
        return $this->objects[$objectKey];
    }

    public function copy(string $sourceObjectKey, string $destinationObjectKey): void
    {
        if (!array_key_exists($sourceObjectKey, $this->objects)) {
            throw new RuntimeException('missing source');
        }
        $body = $this->objects[$sourceObjectKey];
        $this->objects[$destinationObjectKey] = $this->corruptEveryCopy
            ? 'x'
            : ($this->corruptCopiesWithoutChangingSize ? 'X' . substr($body, 1) : $body);
    }

    public function exists(string $objectKey): bool
    {
        return array_key_exists($objectKey, $this->objects);
    }

    public function size(string $objectKey): int
    {
        return $this->sizeOverrides[$objectKey] ?? strlen($this->objects[$objectKey]);
    }

    public function sha256(string $objectKey): string
    {
        return $this->shaOverrides[$objectKey] ?? hash('sha256', $this->objects[$objectKey]);
    }
}
