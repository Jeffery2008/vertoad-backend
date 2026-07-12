<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use Closure;
use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\BackupJobRepositoryInterface;
use VertoAD\Repository\Operations\DatabaseBackupJobRepository;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\Backup\BackupExecutor;
use VertoAD\Service\Operations\Backup\BackupInventory;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;
use VertoAD\Service\Operations\Backup\BackupSourceRegistry;
use VertoAD\Service\Operations\Backup\MysqlBackupRunnerInterface;

final class BackupExecutorTest extends TestCase
{
    private string $tempDirectory;
    private DateTimeImmutable $leaseNow;
    private int $leaseOwnerSequence = 0;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-backup-test-' . bin2hex(random_bytes(6));
        $this->leaseNow = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $this->leaseOwnerSequence = 0;
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
        self::assertSame('vertoad-backup-manifest-v2', $manifest['schema']);
        self::assertSame(
            hash('sha256', $storage->objects['backups/backup_test_id/configuration.json']),
            $manifest['configuration']['sha256'],
        );
        self::assertSame([
            BackupSourceRegistry::ARCHIVE,
            BackupSourceRegistry::ASSETS,
            BackupSourceRegistry::WITHDRAWAL_PROOFS,
        ], array_column($manifest['objects'], 'source_storage'));
        self::assertSame([
            's3://archive/raw.parquet',
            'assets/image.png',
            'proofs/paid.pdf',
        ], array_column($manifest['objects'], 'source_key'));
        foreach ($manifest['objects'] as $object) {
            self::assertSame(
                ['source_storage', 'source_key', 'target_key', 'sha256', 'bytes', 'content_type'],
                array_keys($object),
            );
            self::assertStringStartsWith('backups/backup_test_id/objects/', $object['target_key']);
            self::assertSame(64, strlen($object['sha256']));
            self::assertGreaterThan(0, $object['bytes']);
        }
        self::assertSame('application/vnd.apache.parquet', $manifest['objects'][0]['content_type']);
        self::assertSame('image/png', $manifest['objects'][1]['content_type']);
        self::assertSame('application/pdf', $manifest['objects'][2]['content_type']);
        self::assertSame(0, $storage->copyCalls);
        self::assertSame('vertoad-config-backup-v1', $config['schema']);
        self::assertSame('serving.geo_provider', $config['system_config_versions'][0]['config_key']);
        self::assertSame(['include_builtins' => true], $config['system_config_versions'][0]['value']);
        self::assertSame('operations.backup.completed', $audit->entries[0]->action);
        self::assertDirectoryDoesNotExist($this->tempDirectory . DIRECTORY_SEPARATOR . 'backup_test_id');
    }

    public function testRestoresVerifiedMysqlAndEveryCopiedObjectAndWritesEvidence(): void
    {
        [$executor, $jobs, $storage, $mysql, $audit, $sources] = $this->executor();
        $jobs->save($this->queuedBackup());
        self::assertSame('completed', $executor->executeNextBackup()->status);
        $jobs->save($this->queuedRestore());
        $sources[BackupSourceRegistry::ASSETS]->objects['assets/image.png'] = 'changed';
        $sources[BackupSourceRegistry::WITHDRAWAL_PROOFS]->objects['proofs/paid.pdf'] = 'changed';
        $sources[BackupSourceRegistry::ARCHIVE]->objects['s3://archive/raw.parquet'] = 'changed';

        $result = $executor->executeNextRestore();
        $restored = $jobs->find('restore_test_id');

        self::assertSame('completed', $result->status);
        self::assertSame($mysql->dumpBody, $mysql->restoredBody);
        self::assertSame('asset-body', $sources[BackupSourceRegistry::ASSETS]->objects['assets/image.png']);
        self::assertSame('proof-body', $sources[BackupSourceRegistry::WITHDRAWAL_PROOFS]->objects['proofs/paid.pdf']);
        self::assertSame('parquet-body', $sources[BackupSourceRegistry::ARCHIVE]->objects['s3://archive/raw.parquet']);
        self::assertSame('image/png', $sources[BackupSourceRegistry::ASSETS]->contentTypes['assets/image.png']);
        self::assertSame('application/pdf', $sources[BackupSourceRegistry::WITHDRAWAL_PROOFS]->contentTypes['proofs/paid.pdf']);
        self::assertSame(
            'application/vnd.apache.parquet',
            $sources[BackupSourceRegistry::ARCHIVE]->contentTypes['s3://archive/raw.parquet'],
        );
        self::assertArrayNotHasKey('assets/image.png', $storage->objects);
        self::assertSame(0, $storage->copyCalls);
        self::assertSame(0, $sources[BackupSourceRegistry::ASSETS]->copyCalls);
        self::assertSame(0, $sources[BackupSourceRegistry::WITHDRAWAL_PROOFS]->copyCalls);
        self::assertSame(0, $sources[BackupSourceRegistry::ARCHIVE]->copyCalls);
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

    public function testLeaseLostExecutorCannotFinalizeAndStaleAttemptRetriesWithAuditMetadata(): void
    {
        [$executor, $jobs, $mysql, $audit] = $this->leasedExecutor();
        $jobs->save($this->queuedBackup());
        $mysql->afterDump = function (): void {
            $this->leaseNow = $this->leaseNow->modify('+61 seconds');
        };

        $lost = $executor->executeNextBackup();
        $stale = $jobs->find('backup_test_id');
        self::assertSame('failed', $lost->status);
        self::assertSame('backup_job_lease_lost', $lost->errorMessage);
        self::assertSame('running', $stale?->status);
        self::assertSame(1, $stale?->attemptCount);
        self::assertSame('req-backup_test_id', $stale?->requestId);
        self::assertNull($stale?->manifestObjectKey);
        self::assertSame([], $audit->entries);
        self::assertSame([], glob($this->tempDirectory . DIRECTORY_SEPARATOR . 'backup_test_id-attempt-*') ?: []);

        $mysql->afterDump = null;
        $retried = $executor->executeNextBackup();
        $completed = $jobs->find('backup_test_id');
        self::assertSame('completed', $retried->status);
        self::assertSame('completed', $completed?->status);
        self::assertSame(2, $completed?->attemptCount);
        self::assertSame('executor-worker-2', $completed?->leaseOwner);
        self::assertSame(7, $completed?->requestedByUserId);
        self::assertSame('req-backup_test_id', $completed?->requestId);
        self::assertSame('operations.backup.completed', $audit->entries[0]->action);
        self::assertSame(2, $audit->entries[0]->metadata['attempt_count'] ?? null);
    }

    public function testFailureAfterLeaseExpiryCannotOverwriteTheReclaimableAttempt(): void
    {
        [$executor, $jobs, $mysql, $audit] = $this->leasedExecutor();
        $jobs->save($this->queuedBackup());
        $mysql->afterDump = function (): void {
            $this->leaseNow = $this->leaseNow->modify('+61 seconds');

            throw new RuntimeException('dump interrupted after lease expiry');
        };

        $result = $executor->executeNextBackup();

        self::assertSame('failed', $result->status);
        self::assertSame('backup_job_lease_lost', $result->errorMessage);
        self::assertSame('running', $jobs->find('backup_test_id')?->status);
        self::assertSame(1, $jobs->find('backup_test_id')?->attemptCount);
        self::assertSame([], $audit->entries);
    }

    public function testNonLeaseRepositoryFailureDuringFailurePersistenceIsNotHidden(): void
    {
        $jobs = new ExecutorFailingSaveRepository($this->queuedBackup());
        $mysql = new ExecutorMysqlRunner();
        $mysql->dumpBody = '';
        $executor = new BackupExecutor(
            $jobs,
            $mysql,
            new ExecutorMemoryStorage(),
            new BackupSourceRegistry([]),
            new BackupInventory($this->connection()),
            new AuditLogService(new OperationAuditRepository()),
            'backups',
            $this->tempDirectory,
            ['staging'],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('backup repository offline');
        $executor->executeNextBackup();
    }

    public function testRestoreStopsWhenItsLeaseIsLostBeforeFinalization(): void
    {
        $storage = new ExecutorMemoryStorage();
        $manifestJson = $this->installValidManifest($storage);
        $manifest = json_decode($manifestJson, true, flags: JSON_THROW_ON_ERROR);
        $source = $this->job(
            'backup_test_id',
            'backup',
            null,
            'completed',
            hash('sha256', $manifestJson),
            0,
            (int) $manifest['payload_byte_count'],
        );
        $restore = $this->withLease(
            $this->job('restore_test_id', 'restore', 'backup_test_id', 'running', hash('sha256', $manifestJson)),
            new DateTimeImmutable('2026-07-10T10:01:00Z'),
            new DateTimeImmutable('2026-07-10T10:00:00Z'),
            1,
        );
        $jobs = new ExecutorStaticJobRepository(
            [$source, $restore],
            'backup_job_lease_lost',
        );
        $mysql = new ExecutorMysqlRunner();
        $executor = new BackupExecutor(
            $jobs,
            $mysql,
            $storage,
            new BackupSourceRegistry([]),
            new BackupInventory($this->connection()),
            new AuditLogService(new OperationAuditRepository()),
            'backups',
            $this->tempDirectory,
            ['staging'],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:30Z'),
        );

        $result = $executor->executeNextRestore();

        self::assertSame('failed', $result->status);
        self::assertSame('backup_job_lease_lost', $result->errorMessage);
    }

    public function testHeartbeatRejectsMalformedAndNonPositiveLeaseWindows(): void
    {
        $executor = $this->executor()[0];
        $method = new \ReflectionMethod(BackupExecutor::class, 'heartbeat');
        $method->setAccessible(true);
        $heartbeat = new DateTimeImmutable('2026-07-10T10:00:00Z');
        $jobs = [
            $this->withLease(
                $this->job('backup_bad_lease', 'backup', null, 'running'),
                null,
                null,
                0,
            ),
            $this->withLease(
                $this->job('backup_zero_lease', 'backup', null, 'running'),
                $heartbeat,
                $heartbeat,
                1,
            ),
        ];
        foreach ($jobs as $job) {
            try {
                $method->invoke($executor, $job);
                self::fail('Malformed lease state must be rejected.');
            } catch (RuntimeException $exception) {
                self::assertSame('backup_job_lease_lost', $exception->getMessage());
            }
        }
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

        [$missingExecutor, $missingJobs, , , , $missingSources] = $this->executor();
        $missingJobs->save($this->queuedBackup());
        unset($missingSources[BackupSourceRegistry::ASSETS]->objects['assets/image.png']);
        $missing = $missingExecutor->executeNextBackup();
        self::assertSame('failed', $missing->status);
        self::assertStringContainsString('Critical object is missing', (string) $missing->errorMessage);
    }

    public function testBackupRejectsInvalidCriticalSourceMeasurements(): void
    {
        [$invalidSize, $invalidSizeJobs, , , , $invalidSizeSources] = $this->executor();
        $invalidSizeSources[BackupSourceRegistry::ASSETS]->sizeOverrides['assets/image.png'] = -1;
        $invalidSizeJobs->save($this->queuedBackup());
        self::assertStringContainsString(
            'Critical source object size is invalid',
            (string) $invalidSize->executeNextBackup()->errorMessage,
        );

        [$invalidHash, $invalidHashJobs, , , , $invalidHashSources] = $this->executor();
        $invalidHashSources[BackupSourceRegistry::ASSETS]->shaOverrides['assets/image.png'] = 'invalid';
        $invalidHashJobs->save($this->queuedBackup());
        self::assertStringContainsString(
            'Critical source object checksum is invalid',
            (string) $invalidHash->executeNextBackup()->errorMessage,
        );
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
            'schema' => 'vertoad-backup-manifest-v2',
            'backup_id' => 'other',
        ], 'identifier does not match'];
        yield 'incomplete' => [[
            'schema' => 'vertoad-backup-manifest-v2',
            'backup_id' => 'backup_test_id',
        ], 'payload is incomplete'];
        yield 'invalid mysql' => [[
            'schema' => 'vertoad-backup-manifest-v2',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 7, 'sha256' => 'bad', 'byte_count' => 1],
            'configuration' => ['object_key' => 'config.json', 'sha256' => str_repeat('b', 64), 'byte_count' => 1],
            'objects' => [],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'MySQL payload is invalid'];
        yield 'invalid configuration' => [[
            'schema' => 'vertoad-backup-manifest-v2',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 'mysql.sql', 'sha256' => str_repeat('a', 64), 'byte_count' => 1],
            'configuration' => ['object_key' => '', 'sha256' => 'bad', 'byte_count' => 0],
            'objects' => [],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'configuration payload is invalid'];
        yield 'invalid object' => [[
            'schema' => 'vertoad-backup-manifest-v2',
            'backup_id' => 'backup_test_id',
            'mysql' => ['object_key' => 'mysql.sql', 'sha256' => str_repeat('a', 64), 'byte_count' => 1],
            'configuration' => ['object_key' => 'config.json', 'sha256' => str_repeat('b', 64), 'byte_count' => 1],
            'objects' => [[
                'source_storage' => 'assets',
                'source_key' => '',
                'target_key' => 'copy',
                'sha256' => str_repeat('c', 64),
                'bytes' => -1,
            ]],
            'environment' => 'staging',
            'payload_byte_count' => 2,
        ], 'object inventory is invalid'];
    }

    public function testRestoreRejectsRedirectedAndDuplicateManifestObjects(): void
    {
        $object = [
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png'),
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => strlen('asset-body'),
            'content_type' => 'image/png',
        ];

        [$redirected, $redirectedJobs, $redirectedStorage] = $this->executor();
        $this->installValidRestoreFixture(
            $redirectedJobs,
            $redirectedStorage,
            [array_replace($object, ['target_key' => 'backups/backup_test_id/objects/redirected'])],
        );
        self::assertStringContainsString(
            'object target key is invalid',
            (string) $redirected->executeNextRestore()->errorMessage,
        );

        [$duplicate, $duplicateJobs, $duplicateStorage] = $this->executor();
        $this->installValidRestoreFixture($duplicateJobs, $duplicateStorage, [$object, $object]);
        self::assertStringContainsString(
            'inventory contains duplicates',
            (string) $duplicate->executeNextRestore()->errorMessage,
        );
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
        $missingTarget = $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png');
        $this->installValidRestoreFixture($copyJobs, $copyStorage, [[
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $missingTarget,
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => 10,
            'content_type' => 'image/png',
        ]]);
        self::assertStringContainsString('could not find backup object', (string) $missingCopy->executeNextRestore()->errorMessage);

        [$checksum, $checksumJobs, $checksumStorage] = $this->executor();
        $this->installValidRestoreFixture($checksumJobs, $checksumStorage);
        $originalDump = $checksumStorage->objects['backups/backup_test_id/mysql.sql'];
        $checksumStorage->objects['backups/backup_test_id/mysql.sql'] = 'X' . substr($originalDump, 1);
        self::assertStringContainsString('checksum verification failed', (string) $checksum->executeNextRestore()->errorMessage);
    }

    public function testBackupAndRestoreRejectSizeAndConfigurationIntegrityFailures(): void
    {
        [$copyBackup, $copyBackupJobs, $copyBackupStorage] = $this->executor();
        $copyBackupStorage->corruptPutFiles = true;
        $copyBackupJobs->save($this->queuedBackup());
        self::assertStringContainsString('backup size verification failed', (string) $copyBackup->executeNextBackup()->errorMessage);

        [$requiredSize, $requiredSizeJobs, $requiredSizeStorage] = $this->executor();
        $this->installValidRestoreFixture($requiredSizeJobs, $requiredSizeStorage);
        $requiredSizeStorage->sizeOverrides['backups/backup_test_id/mysql.sql'] = 999;
        self::assertStringContainsString('backup object size verification failed', (string) $requiredSize->executeNextRestore()->errorMessage);

        [$copiedSize, $copiedSizeJobs, $copiedSizeStorage] = $this->executor();
        $copiedObject = $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png');
        $copiedSizeStorage->objects[$copiedObject] = 'asset-body';
        $this->installValidRestoreFixture($copiedSizeJobs, $copiedSizeStorage, [[
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $copiedObject,
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => strlen('asset-body'),
            'content_type' => 'image/png',
        ]]);
        $copiedSizeStorage->sizeOverrides[$copiedObject] = 999;
        self::assertStringContainsString('backed-up object size verification failed', (string) $copiedSize->executeNextRestore()->errorMessage);

        [$configHash, $configHashJobs, $configHashStorage] = $this->executor();
        $this->installValidRestoreFixture($configHashJobs, $configHashStorage);
        $configHashStorage->objects['backups/backup_test_id/configuration.json'] = '{"schema":"tampered"}';
        $configHashStorage->sizeOverrides['backups/backup_test_id/configuration.json'] = strlen($this->validConfigJson());
        self::assertStringContainsString('backup object checksum', (string) $configHash->executeNextRestore()->errorMessage);

        [$configSchema, $configSchemaJobs, $configSchemaStorage] = $this->executor();
        $this->installValidRestoreFixture($configSchemaJobs, $configSchemaStorage, configJson: '{"schema":"other"}');
        self::assertStringContainsString('Configuration backup schema', (string) $configSchema->executeNextRestore()->errorMessage);

        [$restoredSize, $restoredSizeJobs, $restoredSizeStorage, , , $restoredSizeSources] = $this->executor();
        $restoredObject = $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png');
        $restoredSizeStorage->objects[$restoredObject] = 'asset-body';
        $this->installValidRestoreFixture($restoredSizeJobs, $restoredSizeStorage, [[
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $restoredObject,
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => strlen('asset-body'),
            'content_type' => 'image/png',
        ]]);
        $restoredSizeSources[BackupSourceRegistry::ASSETS]->corruptPutFiles = true;
        self::assertStringContainsString('Restored object size verification failed', (string) $restoredSize->executeNextRestore()->errorMessage);
        self::assertSame('completed', $restoredSizeJobs->find('backup_test_id')?->status);
        self::assertSame('failed', $restoredSizeJobs->find('restore_test_id')?->status);
    }

    public function testRestoreRejectsObjectsThatChangeBetweenMetadataChecksAndDownloads(): void
    {
        $configKey = 'backups/backup_test_id/configuration.json';
        [$configRead, $configReadJobs, $configReadStorage] = $this->executor();
        $this->installValidRestoreFixture($configReadJobs, $configReadStorage);
        $trustedConfig = $configReadStorage->objects[$configKey];
        $configReadStorage->objects[$configKey] = '{"schema":"tampered"}';
        $configReadStorage->sizeOverrides[$configKey] = strlen($trustedConfig);
        $configReadStorage->shaOverrides[$configKey] = hash('sha256', $trustedConfig);
        self::assertStringContainsString(
            'Configuration backup checksum verification failed',
            (string) $configRead->executeNextRestore()->errorMessage,
        );

        $mysqlKey = 'backups/backup_test_id/mysql.sql';
        [$downloadSize, $downloadSizeJobs, $downloadSizeStorage] = $this->executor();
        $this->installValidRestoreFixture($downloadSizeJobs, $downloadSizeStorage);
        $downloadSizeStorage->downloadOverrides[$mysqlKey] = 'short';
        self::assertStringContainsString(
            'MySQL backup download size verification failed',
            (string) $downloadSize->executeNextRestore()->errorMessage,
        );

        [$downloadHash, $downloadHashJobs, $downloadHashStorage] = $this->executor();
        $this->installValidRestoreFixture($downloadHashJobs, $downloadHashStorage);
        $trustedDump = $downloadHashStorage->objects[$mysqlKey];
        $downloadHashStorage->downloadOverrides[$mysqlKey] = 'X' . substr($trustedDump, 1);
        self::assertStringContainsString(
            'MySQL backup download checksum verification failed',
            (string) $downloadHash->executeNextRestore()->errorMessage,
        );
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
        $storage->corruptPutFilesWithoutChangingSize = true;
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
        $copiedObject = $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png');
        $copiedStorage->objects[$copiedObject] = 'asset-body';
        $this->installValidRestoreFixture($copiedJobs, $copiedStorage, [[
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $copiedObject,
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => strlen('asset-body'),
            'content_type' => 'image/png',
        ]]);
        $copiedStorage->objects[$copiedObject] = 'Asset-body';
        self::assertStringContainsString(
            'backed-up object checksum',
            (string) $copiedTamper->executeNextRestore()->errorMessage,
        );

        [$restoredTamper, $restoredJobs, $restoredStorage, , , $restoredSources] = $this->executor();
        $restoredObject = $this->targetKey(BackupSourceRegistry::ASSETS, 'assets/image.png');
        $restoredStorage->objects[$restoredObject] = 'asset-body';
        $this->installValidRestoreFixture($restoredJobs, $restoredStorage, [[
            'source_storage' => BackupSourceRegistry::ASSETS,
            'source_key' => 'assets/image.png',
            'target_key' => $restoredObject,
            'sha256' => hash('sha256', 'asset-body'),
            'bytes' => strlen('asset-body'),
            'content_type' => 'image/png',
        ]]);
        $restoredSources[BackupSourceRegistry::ASSETS]->corruptPutFilesWithoutChangingSize = true;
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
     * @return array{BackupExecutor,InMemoryBackupJobRepository,ExecutorMemoryStorage,ExecutorMysqlRunner,OperationAuditRepository,array<string,ExecutorMemoryStorage>}
     */
    private function executor(
        array $restoreAllowedEnvironments = ['staging'],
        string $baseObjectKey = 'backups',
        ?string $tempDirectory = null,
    ): array
    {
        $jobs = new InMemoryBackupJobRepository();
        $storage = new ExecutorMemoryStorage();
        $sources = [
            BackupSourceRegistry::ASSETS => new ExecutorMemoryStorage(['assets/image.png' => 'asset-body']),
            BackupSourceRegistry::WITHDRAWAL_PROOFS => new ExecutorMemoryStorage(['proofs/paid.pdf' => 'proof-body']),
            BackupSourceRegistry::ARCHIVE => new ExecutorMemoryStorage(['s3://archive/raw.parquet' => 'parquet-body']),
        ];
        $mysql = new ExecutorMysqlRunner();
        $audit = new OperationAuditRepository();
        $executor = new BackupExecutor(
            $jobs,
            $mysql,
            $storage,
            new BackupSourceRegistry($sources),
            new BackupInventory($this->connection()),
            new AuditLogService($audit),
            $baseObjectKey,
            $tempDirectory ?? $this->tempDirectory,
            $restoreAllowedEnvironments,
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );

        return [$executor, $jobs, $storage, $mysql, $audit, $sources];
    }

    /** @return array{BackupExecutor,DatabaseBackupJobRepository,ExecutorMysqlRunner,OperationAuditRepository} */
    private function leasedExecutor(): array
    {
        $connection = $this->connection();
        $this->createBackupJobTable($connection);
        $jobs = new DatabaseBackupJobRepository(
            $connection,
            60,
            function (): string {
                return 'executor-worker-' . ++$this->leaseOwnerSequence;
            },
            fn (): DateTimeImmutable => $this->leaseNow,
        );
        $sources = [
            BackupSourceRegistry::ASSETS => new ExecutorMemoryStorage(['assets/image.png' => 'asset-body']),
            BackupSourceRegistry::WITHDRAWAL_PROOFS => new ExecutorMemoryStorage(['proofs/paid.pdf' => 'proof-body']),
            BackupSourceRegistry::ARCHIVE => new ExecutorMemoryStorage(['s3://archive/raw.parquet' => 'parquet-body']),
        ];
        $mysql = new ExecutorMysqlRunner();
        $audit = new OperationAuditRepository();
        $executor = new BackupExecutor(
            $jobs,
            $mysql,
            new ExecutorMemoryStorage(),
            new BackupSourceRegistry($sources),
            new BackupInventory($connection),
            new AuditLogService($audit),
            'backups',
            $this->tempDirectory,
            ['staging'],
            fn (): DateTimeImmutable => $this->leaseNow,
        );

        return [$executor, $jobs, $mysql, $audit];
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
        $connection->executeStatement('CREATE TABLE creative_assets (object_key VARCHAR(512), content_type VARCHAR(120))');
        $connection->insert('creative_assets', ['object_key' => 'assets/image.png', 'content_type' => 'image/png']);
        $connection->executeStatement('CREATE TABLE withdrawal_proofs (object_key VARCHAR(512), content_type VARCHAR(120), status VARCHAR(32))');
        $connection->insert('withdrawal_proofs', ['object_key' => 'proofs/paid.pdf', 'content_type' => 'application/pdf', 'status' => 'verified']);
        $connection->insert('withdrawal_proofs', ['object_key' => 'proofs/pending.pdf', 'content_type' => 'application/pdf', 'status' => 'pending_upload']);
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

    private function createBackupJobTable(Connection $connection): void
    {
        $connection->executeStatement(<<<'SQL'
CREATE TABLE operation_backup_jobs (
    job_id VARCHAR(64) PRIMARY KEY,
    job_type VARCHAR(16),
    source_backup_id VARCHAR(64) NULL,
    status VARCHAR(16),
    requested_by_user_id INTEGER,
    request_id VARCHAR(160),
    environment VARCHAR(32),
    reason VARCHAR(500) NULL,
    manifest_object_key VARCHAR(1024) NULL,
    manifest_sha256 VARCHAR(64) NULL,
    mysql_object_key VARCHAR(1024) NULL,
    mysql_sha256 VARCHAR(64) NULL,
    config_object_key VARCHAR(1024) NULL,
    evidence_object_key VARCHAR(1024) NULL,
    object_count INTEGER,
    byte_count INTEGER,
    error_message TEXT NULL,
    created_at VARCHAR(32),
    started_at VARCHAR(32) NULL,
    completed_at VARCHAR(32) NULL,
    lease_owner VARCHAR(128) NULL,
    lease_expires_at VARCHAR(32) NULL,
    attempt_count INTEGER NOT NULL DEFAULT 0,
    heartbeat_at VARCHAR(32) NULL
)
SQL);
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

    /** @param list<array{source_storage:string,source_key:string,target_key:string,sha256:string,bytes:int,content_type:string}> $objects */
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

    /** @param list<array{source_storage:string,source_key:string,target_key:string,sha256:string,bytes:int,content_type:string}> $objects */
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
            'schema' => 'vertoad-backup-manifest-v2',
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
                + array_sum(array_column($objects, 'bytes')),
        ], JSON_THROW_ON_ERROR);
        $storage->objects['backups/backup_test_id/manifest.json'] = $manifestJson;

        return $manifestJson;
    }

    private function validConfigJson(): string
    {
        return '{"schema":"vertoad-config-backup-v1","system_config_versions":[]}';
    }

    private function targetKey(string $sourceStorage, string $sourceKey): string
    {
        $path = parse_url($sourceKey, PHP_URL_PATH);
        $name = basename(is_string($path) ? $path : $sourceKey);

        return 'backups/backup_test_id/objects/'
            . $sourceStorage
            . '/'
            . hash('sha256', $sourceKey)
            . '/'
            . $name;
    }

    private function withLease(
        BackupJob $job,
        ?DateTimeImmutable $leaseExpiresAt,
        ?DateTimeImmutable $heartbeatAt,
        int $attemptCount,
    ): BackupJob {
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
            mysqlSha256: $job->mysqlSha256,
            configObjectKey: $job->configObjectKey,
            evidenceObjectKey: $job->evidenceObjectKey,
            objectCount: $job->objectCount,
            byteCount: $job->byteCount,
            errorMessage: $job->errorMessage,
            createdAt: $job->createdAt,
            startedAt: $job->startedAt,
            completedAt: $job->completedAt,
            leaseOwner: 'executor-test-owner',
            leaseExpiresAt: $leaseExpiresAt,
            attemptCount: $attemptCount,
            heartbeatAt: $heartbeatAt,
        );
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
    public ?Closure $afterDump = null;

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
        if ($this->afterDump !== null) {
            ($this->afterDump)();
        }
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
    public bool $corruptPutFiles = false;
    public bool $corruptPutFilesWithoutChangingSize = false;
    public int $copyCalls = 0;
    /** @var array<string, int> */
    public array $sizeOverrides = [];
    /** @var array<string, string> */
    public array $shaOverrides = [];
    /** @var array<string, string> */
    public array $contentTypes = [];
    /** @var array<string, string> */
    public array $downloadOverrides = [];
    /** @param array<string, string> $objects */
    public function __construct(public array $objects = [])
    {
    }

    public function putFile(string $objectKey, string $localPath, string $contentType): void
    {
        $body = (string) file_get_contents($localPath);
        $canCorrupt = !str_ends_with($objectKey, '/mysql.sql');
        $this->objects[$objectKey] = $this->corruptPutFiles && $canCorrupt
            ? 'x'
            : ($this->corruptPutFilesWithoutChangingSize && $canCorrupt ? 'X' . substr($body, 1) : $body);
        $this->contentTypes[$objectKey] = $contentType;
    }

    public function putString(string $objectKey, string $body, string $contentType): void
    {
        $this->objects[$objectKey] = $body;
        $this->contentTypes[$objectKey] = $contentType;
    }

    public function getFile(string $objectKey, string $localPath): void
    {
        file_put_contents($localPath, $this->downloadOverrides[$objectKey] ?? $this->objects[$objectKey]);
    }

    public function readString(string $objectKey): string
    {
        return $this->objects[$objectKey];
    }

    public function copy(string $sourceObjectKey, string $destinationObjectKey): void
    {
        ++$this->copyCalls;
        throw new RuntimeException('CopyObject must not be used for backup source transfers.');
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

final class ExecutorFailingSaveRepository implements BackupJobRepositoryInterface
{
    private InMemoryBackupJobRepository $jobs;

    public function __construct(BackupJob $seed)
    {
        $this->jobs = new InMemoryBackupJobRepository();
        $this->jobs->save($seed);
    }

    public function save(BackupJob $job): BackupJob
    {
        throw new RuntimeException('backup repository offline');
    }

    public function find(string $jobId): ?BackupJob
    {
        return $this->jobs->find($jobId);
    }

    public function list(?string $jobType, int $limit, int $offset): array
    {
        return $this->jobs->list($jobType, $limit, $offset);
    }

    public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob
    {
        return $this->jobs->claimNext($jobType, $startedAt);
    }

    public function latestCompleted(string $jobType): ?BackupJob
    {
        return $this->jobs->latestCompleted($jobType);
    }
}

final class ExecutorStaticJobRepository implements BackupJobRepositoryInterface
{
    /** @var array<string, BackupJob> */
    private array $jobs = [];
    private bool $claimed = false;

    /** @param list<BackupJob> $jobs */
    public function __construct(array $jobs, private readonly ?string $saveError = null)
    {
        foreach ($jobs as $job) {
            $this->jobs[$job->jobId] = $job;
        }
    }

    public function save(BackupJob $job): BackupJob
    {
        if ($this->saveError !== null) {
            throw new RuntimeException($this->saveError);
        }
        $this->jobs[$job->jobId] = $job;

        return $job;
    }

    public function find(string $jobId): ?BackupJob
    {
        return $this->jobs[$jobId] ?? null;
    }

    public function list(?string $jobType, int $limit, int $offset): array
    {
        $items = array_values(array_filter(
            $this->jobs,
            static fn (BackupJob $job): bool => $jobType === null || $job->jobType === $jobType,
        ));

        return ['items' => array_slice($items, $offset, $limit), 'total' => count($items)];
    }

    public function claimNext(string $jobType, DateTimeImmutable $startedAt): ?BackupJob
    {
        if ($this->claimed) {
            return null;
        }
        $this->claimed = true;
        foreach ($this->jobs as $job) {
            if ($job->jobType === $jobType && $job->status === 'running') {
                return $job;
            }
        }

        return null;
    }

    public function latestCompleted(string $jobType): ?BackupJob
    {
        foreach (array_reverse($this->jobs) as $job) {
            if ($job->jobType === $jobType && $job->status === 'completed') {
                return $job;
            }
        }

        return null;
    }
}
