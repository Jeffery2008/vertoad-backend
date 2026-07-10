<?php

declare(strict_types=1);

namespace VertoAD\Tests\Cron;

use DateTimeImmutable;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Infrastructure\Storage\UnavailableBackupObjectStorage;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Cron\BackupCreateJob;
use VertoAD\Service\Cron\BackupRestoreJob;
use VertoAD\Service\Operations\Backup\BackupExecutor;
use VertoAD\Service\Operations\Backup\BackupInventory;
use VertoAD\Service\Operations\Backup\BackupObjectStorageInterface;
use VertoAD\Service\Operations\Backup\MysqlBackupRunnerInterface;
use VertoAD\Tests\Operations\OperationAuditRepository;

final class BackupExecutionJobTest extends TestCase
{
    private string $tempDirectory;

    protected function setUp(): void
    {
        $this->tempDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vertoad-backup-cron-' . bin2hex(random_bytes(5));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tempDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $directory) {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($directory);
        }
        @rmdir($this->tempDirectory);
    }

    public function testCronJobsReturnIdleCompletedAndFailedResults(): void
    {
        [$executor, $jobs] = $this->executor(new CronBackupStorage());
        $create = new BackupCreateJob($executor);
        $restore = new BackupRestoreJob($executor);

        self::assertSame('backup-create', $create->name());
        self::assertSame('backup-restore', $restore->name());
        $idleCreate = $create->run();
        $idleRestore = $restore->run();
        self::assertSame('completed', $idleCreate->status);
        self::assertSame('No backup job is queued.', $idleCreate->message);
        self::assertSame(0, $idleCreate->metrics['jobs_processed']);
        self::assertSame('completed', $idleRestore->status);
        self::assertSame('No restore job is queued.', $idleRestore->message);

        $jobs->save($this->job('backup_cron_id', 'backup', null, 'queued'));
        $completed = $create->run();
        self::assertSame('completed', $completed->status);
        self::assertNull($completed->message);
        self::assertSame(1, $completed->metrics['jobs_processed']);

        $jobs->save($this->job('restore_cron_id', 'restore', 'backup_cron_id', 'queued'));
        $restored = $restore->run();
        self::assertSame('completed', $restored->status);
        self::assertSame(1, $restored->metrics['jobs_processed']);

        [$failedExecutor, $failedJobs] = $this->executor(new UnavailableBackupObjectStorage('storage unavailable'));
        $failedJobs->save($this->job('backup_fail_id', 'backup', null, 'queued'));
        $failed = (new BackupCreateJob($failedExecutor))->run();
        self::assertSame('failed', $failed->status);
        self::assertSame('storage unavailable', $failed->message);

        $failedJobs->save($this->job('restore_fail_id', 'restore', 'missing', 'queued'));
        $failedRestore = (new BackupRestoreJob($failedExecutor))->run();
        self::assertSame('failed', $failedRestore->status);
        self::assertStringContainsString('source backup', (string) $failedRestore->message);
    }

    public function testUnavailableStorageFailsEveryMutationAndReadWithoutPretendingObjectsExist(): void
    {
        $storage = new UnavailableBackupObjectStorage('not configured');
        self::assertFalse($storage->exists('any'));
        foreach (
            [
                static fn () => $storage->putFile('key', 'path', 'type'),
                static fn () => $storage->putString('key', 'body', 'type'),
                static fn () => $storage->getFile('key', 'path'),
                static fn () => $storage->readString('key'),
                static fn () => $storage->copy('source', 'destination'),
                static fn () => $storage->size('key'),
                static fn () => $storage->sha256('key'),
            ] as $operation
        ) {
            try {
                $operation();
                self::fail('Unavailable storage must fail operations.');
            } catch (RuntimeException $exception) {
                self::assertSame('not configured', $exception->getMessage());
            }
        }
    }

    /** @return array{BackupExecutor,InMemoryBackupJobRepository} */
    private function executor(BackupObjectStorageInterface $storage): array
    {
        $jobs = new InMemoryBackupJobRepository();
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $executor = new BackupExecutor(
            $jobs,
            new CronMysqlRunner(),
            $storage,
            new BackupInventory($connection),
            new AuditLogService(new OperationAuditRepository()),
            'backups',
            $this->tempDirectory,
            ['staging'],
            static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
        );

        return [$executor, $jobs];
    }

    private function job(string $id, string $type, ?string $source, string $status): BackupJob
    {
        return new BackupJob(
            jobId: $id,
            jobType: $type,
            sourceBackupId: $source,
            status: $status,
            requestedByUserId: 1,
            requestId: 'req-' . $id,
            environment: 'staging',
            reason: $type === 'restore' ? 'Cron restore drill reason' : null,
            manifestObjectKey: $type === 'restore' ? 'backups/backup_cron_id/manifest.json' : null,
            manifestSha256: $type === 'restore' ? str_repeat('b', 64) : null,
            mysqlObjectKey: null,
            mysqlSha256: null,
            configObjectKey: null,
            evidenceObjectKey: null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: null,
            createdAt: new DateTimeImmutable('2026-07-10T09:00:00Z'),
            startedAt: null,
            completedAt: null,
        );
    }
}

final class CronMysqlRunner implements MysqlBackupRunnerInterface
{
    public function dump(string $destinationPath): void
    {
        file_put_contents($destinationPath, 'SQL');
    }

    public function restore(string $sourcePath): void
    {
        if (file_get_contents($sourcePath) !== 'SQL') {
            throw new RuntimeException('unexpected dump');
        }
    }
}

final class CronBackupStorage implements BackupObjectStorageInterface
{
    /** @var array<string, string> */
    private array $objects = [];

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
        $this->objects[$destinationObjectKey] = $this->objects[$sourceObjectKey];
    }

    public function exists(string $objectKey): bool
    {
        return isset($this->objects[$objectKey]);
    }

    public function size(string $objectKey): int
    {
        return strlen($this->objects[$objectKey]);
    }

    public function sha256(string $objectKey): string
    {
        return hash('sha256', $this->objects[$objectKey]);
    }
}
