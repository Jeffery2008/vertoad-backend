<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\AuditLogService;
use VertoAD\Service\Operations\Backup\BackupService;

final class BackupServiceTest extends TestCase
{
    public function testQueuesListsAndReadsBackupWithAuditContext(): void
    {
        [$service, $jobs, $audit] = $this->service();

        $job = $service->queueBackup(7, ' req-backup ');
        $list = $service->list(null, 1, 0);

        self::assertSame('backup_fixed_id', $job->jobId);
        self::assertSame('queued', $job->status);
        self::assertSame('staging', $job->environment);
        self::assertSame('req-backup', $job->requestId);
        self::assertSame('backup_fixed_id', $jobs->find('backup_fixed_id')?->jobId);
        self::assertSame('backup_fixed_id', $service->get('backup_fixed_id')['job_id']);
        self::assertSame('backup_fixed_id', $list['items'][0]['job_id']);
        self::assertSame(['limit' => 1, 'offset' => 0, 'total' => 1, 'has_more' => false], $list['page']);
        self::assertSame('operations.backup.queued', $audit->entries[0]->action);
        self::assertSame('req-backup', $audit->entries[0]->requestId);
    }

    public function testQueuesRestoreOnlyForCompletedBackupInAllowedEnvironment(): void
    {
        [$service, $jobs, $audit] = $this->service(id: static fn (string $prefix): string => $prefix . '_fixed_id');
        $jobs->save($this->completedBackup());

        $restore = $service->queueRestore(
            'backup_source_id',
            ' backup_source_id ',
            'Scheduled staging restore drill',
            9,
            'req-restore',
        );

        self::assertSame('restore_fixed_id', $restore->jobId);
        self::assertSame('backup_source_id', $restore->sourceBackupId);
        self::assertSame('backups/backup_source_id/manifest.json', $restore->manifestObjectKey);
        self::assertSame(str_repeat('b', 64), $restore->manifestSha256);
        self::assertSame(str_repeat('b', 64), $service->serialize($restore)['manifest_sha256']);
        self::assertSame('operations.backup.restore_queued', $audit->entries[0]->action);
        self::assertSame('Scheduled staging restore drill', $audit->entries[0]->metadata['reason'] ?? null);
        self::assertSame('restore_fixed_id', $service->list('restore')['items'][0]['job_id']);
        $jobs->save($this->completedBackup(id: 'backup_other_id'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('restore_already_queued');
        $service->queueRestore('backup_other_id', 'backup_other_id', 'Another staging restore drill', 9, 'req-two');
    }

    public function testFailedRestoreCanBeRetriedWithoutOverwritingItsAuditMetadata(): void
    {
        $sequence = 0;
        [$service, $jobs, $audit] = $this->service(id: static function (string $prefix) use (&$sequence): string {
            return $prefix . '_retry_' . ++$sequence;
        });
        $jobs->save($this->completedBackup());
        $first = $service->queueRestore(
            'backup_source_id',
            'backup_source_id',
            'First scheduled restore drill',
            9,
            'req-first-restore',
        );
        $jobs->save(new BackupJob(
            jobId: $first->jobId,
            jobType: $first->jobType,
            sourceBackupId: $first->sourceBackupId,
            status: 'failed',
            requestedByUserId: $first->requestedByUserId,
            requestId: $first->requestId,
            environment: $first->environment,
            reason: $first->reason,
            manifestObjectKey: $first->manifestObjectKey,
            manifestSha256: $first->manifestSha256,
            mysqlObjectKey: $first->mysqlObjectKey,
            mysqlSha256: $first->mysqlSha256,
            configObjectKey: $first->configObjectKey,
            evidenceObjectKey: null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: 'interrupted after preflight',
            createdAt: $first->createdAt,
            startedAt: new DateTimeImmutable('2026-07-10T10:01:00Z'),
            completedAt: new DateTimeImmutable('2026-07-10T10:02:00Z'),
        ));

        $retry = $service->queueRestore(
            'backup_source_id',
            'backup_source_id',
            'Retry scheduled restore drill',
            10,
            'req-retry-restore',
        );

        self::assertSame('restore_retry_2', $retry->jobId);
        self::assertSame('queued', $retry->status);
        self::assertSame('failed', $jobs->find('restore_retry_1')?->status);
        self::assertSame('req-first-restore', $jobs->find('restore_retry_1')?->requestId);
        self::assertSame('First scheduled restore drill', $jobs->find('restore_retry_1')?->reason);
        self::assertSame('interrupted after preflight', $jobs->find('restore_retry_1')?->errorMessage);
        self::assertSame(2, count($audit->entries));
    }

    #[DataProvider('invalidRestoreProvider')]
    public function testRejectsInvalidRestoreRequests(
        string $environment,
        string $backupStatus,
        string $backupId,
        string $confirmation,
        string $reason,
        string $expected,
    ): void {
        [$service, $jobs] = $this->service(environment: $environment);
        if ($backupId === 'backup_source_id') {
            $jobs->save($this->completedBackup($backupStatus));
        }

        $this->expectExceptionMessage($expected);
        $service->queueRestore($backupId, $confirmation, $reason, 7, 'req-restore');
    }

    /** @return iterable<string, array{string,string,string,string,string,string}> */
    public static function invalidRestoreProvider(): iterable
    {
        yield 'production forbidden' => ['production', 'completed', 'backup_source_id', 'backup_source_id', 'Valid restore reason', 'not allowed'];
        yield 'missing backup' => ['staging', 'completed', 'missing', 'missing', 'Valid restore reason', 'backup_not_found'];
        yield 'not completed' => ['staging', 'queued', 'backup_source_id', 'backup_source_id', 'Valid restore reason', 'backup_not_restorable'];
        yield 'wrong confirmation' => ['staging', 'completed', 'backup_source_id', 'wrong', 'Valid restore reason', 'confirmation must exactly match'];
        yield 'short reason' => ['staging', 'completed', 'backup_source_id', 'backup_source_id', 'short', 'between 10 and 500'];
        yield 'long reason' => ['staging', 'completed', 'backup_source_id', 'backup_source_id', str_repeat('x', 501), 'between 10 and 500'];
    }

    public function testValidatesPaginationJobTypesActorsRequestsAndGeneratedIds(): void
    {
        [$service] = $this->service();
        foreach (
            [
                static fn () => $service->queueBackup(0, 'req'),
                static fn () => $service->queueBackup(1, ' '),
                static fn () => $service->list('invalid'),
                static fn () => $service->list(null, 0),
                static fn () => $service->list(null, 101),
                static fn () => $service->list(null, 10, -1),
            ] as $invalid
        ) {
            try {
                $invalid();
                self::fail('Expected request validation failure.');
            } catch (InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        try {
            $service->get('missing');
            self::fail('Expected missing job failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('backup_job_not_found', $exception->getMessage());
        }

        [$badIdService] = $this->service(id: static fn (string $prefix): string => 'bad');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid identifier');
        $badIdService->queueBackup(1, 'req');
    }

    public function testDefaultsBlankEnvironmentToProductionAndSupportsRandomIds(): void
    {
        $jobs = new InMemoryBackupJobRepository();
        $audit = new OperationAuditRepository();
        $service = new BackupService($jobs, new AuditLogService($audit), '  ', ['staging']);

        $job = $service->queueBackup(4, 'req-random');

        self::assertSame('production', $job->environment);
        self::assertMatchesRegularExpression('/^backup_[a-f0-9]{32}$/', $job->jobId);
    }

    /**
     * @param callable(string):string|null $id
     * @return array{BackupService, InMemoryBackupJobRepository, OperationAuditRepository}
     */
    private function service(
        string $environment = 'staging',
        ?callable $id = null,
    ): array {
        $jobs = new InMemoryBackupJobRepository();
        $audit = new OperationAuditRepository();
        $id ??= static fn (string $prefix): string => $prefix . '_fixed_id';

        return [
            new BackupService(
                $jobs,
                new AuditLogService($audit),
                $environment,
                ['staging'],
                $id(...),
                static fn (): DateTimeImmutable => new DateTimeImmutable('2026-07-10T10:00:00Z'),
            ),
            $jobs,
            $audit,
        ];
    }

    private function completedBackup(string $status = 'completed', string $id = 'backup_source_id'): BackupJob
    {
        return new BackupJob(
            jobId: $id,
            jobType: 'backup',
            sourceBackupId: null,
            status: $status,
            requestedByUserId: 1,
            requestId: 'req-source',
            environment: 'staging',
            reason: null,
            manifestObjectKey: $status === 'completed' ? 'backups/' . $id . '/manifest.json' : null,
            manifestSha256: $status === 'completed' ? str_repeat('b', 64) : null,
            mysqlObjectKey: $status === 'completed' ? 'backups/' . $id . '/mysql.sql' : null,
            mysqlSha256: $status === 'completed' ? str_repeat('a', 64) : null,
            configObjectKey: $status === 'completed' ? 'backups/' . $id . '/configuration.json' : null,
            evidenceObjectKey: null,
            objectCount: 2,
            byteCount: 200,
            errorMessage: null,
            createdAt: new DateTimeImmutable('2026-07-10T08:00:00Z'),
            startedAt: new DateTimeImmutable('2026-07-10T08:01:00Z'),
            completedAt: $status === 'completed' ? new DateTimeImmutable('2026-07-10T08:02:00Z') : null,
        );
    }
}
