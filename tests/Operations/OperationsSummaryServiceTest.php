<?php

declare(strict_types=1);

namespace VertoAD\Tests\Operations;

use DateTimeImmutable;
use Defuse\Crypto\Key;
use PHPUnit\Framework\TestCase;
use VertoAD\AppFactory;
use VertoAD\Domain\IpGeo\GeoIpRecord;
use VertoAD\Domain\Operations\BackupJob;
use VertoAD\Repository\IpGeo\InMemoryIpGeoRepository;
use VertoAD\Repository\Operations\InMemoryBackupJobRepository;
use VertoAD\Service\Operations\OperationsSummaryService;

final class OperationsSummaryServiceTest extends TestCase
{
    public function testSummaryExposesBackupRestoreDrillAndRedisHardeningInventory(): void
    {
        $serviceClass = 'VertoAD\\Service\\Operations\\OperationsSummaryService';
        self::assertTrue(class_exists($serviceClass), $serviceClass . ' must exist.');

        $service = new $serviceClass(
            backupStatus: [
                'status' => 'healthy',
                'last_backup_at' => '2026-06-08T01:00:00Z',
                'last_successful_backup_id' => 'backup_20260608_0100',
            ],
            restoreDrillEvidence: [
                'last_drill_at' => '2026-06-07T02:00:00Z',
                'evidence_url' => 's3://vertoad-backups/drills/2026-06-07.json',
                'verified_by' => 'ops-user-1',
            ],
            redisHardeningInventory: [
                'password_configured' => true,
                'dangerous_commands_disabled' => ['FLUSHALL', 'FLUSHDB', 'CONFIG'],
                'key_prefix' => 'vertoad:prod:',
                'prefix_collision_risk' => 'low',
                'auth_failure_alerting_configured' => true,
            ],
        );

        $summary = $service->summary();

        self::assertSame('healthy', $this->value($this->value($summary, 'backup_status'), 'status'));
        self::assertSame('backup_20260608_0100', $this->value($this->value($summary, 'backup_status'), 'last_successful_backup_id'));
        self::assertSame('2026-06-07T02:00:00Z', $this->value($this->value($summary, 'restore_drill_evidence'), 'last_drill_at'));
        self::assertTrue($this->value($this->value($summary, 'redis_hardening_inventory'), 'password_configured'));
        self::assertSame(['FLUSHALL', 'FLUSHDB', 'CONFIG'], $this->value($this->value($summary, 'redis_hardening_inventory'), 'dangerous_commands_disabled'));
        self::assertSame('vertoad:prod:', $this->value($this->value($summary, 'redis_hardening_inventory'), 'key_prefix'));
        self::assertSame('low', $this->value($this->value($summary, 'redis_hardening_inventory'), 'prefix_collision_risk'));
        self::assertTrue($this->value($this->value($summary, 'redis_hardening_inventory'), 'auth_failure_alerting_configured'));
        self::assertTrue($summary['audit_on_view']['backup_restore']);
    }

    public function testSummaryDerivesBackupAndRestoreEvidenceFromPersistedJobs(): void
    {
        $jobs = new InMemoryBackupJobRepository();
        $service = new OperationsSummaryService(
            backupStatus: ['status' => 'stale-static-value'],
            restoreDrillEvidence: ['last_drill_at' => 'stale-static-value'],
            redisHardeningInventory: [],
            backupJobs: $jobs,
        );

        self::assertSame([
            'status' => 'unknown',
            'last_backup_at' => null,
            'last_successful_backup_id' => null,
        ], $service->summary()['backup_status']);

        $jobs->save($this->backupJob('backup_queued', 'backup', 'queued', '2026-07-10T01:00:00Z'));
        self::assertSame('pending', $service->summary()['backup_status']['status']);

        $jobs->save($this->backupJob('backup_failed', 'backup', 'failed', '2026-07-10T02:00:00Z'));
        self::assertSame('unhealthy', $service->summary()['backup_status']['status']);

        $jobs->save($this->backupJob('backup_completed', 'backup', 'completed', '2026-07-10T03:00:00Z'));
        self::assertSame([
            'status' => 'healthy',
            'last_backup_at' => '2026-07-10T03:01:00+00:00',
            'last_successful_backup_id' => 'backup_completed',
        ], $service->summary()['backup_status']);

        $jobs->save($this->backupJob('restore_completed', 'restore', 'completed', '2026-07-10T04:00:00Z'));
        self::assertSame([
            'last_drill_at' => '2026-07-10T04:01:00+00:00',
            'evidence_url' => 'backups/restore-evidence/restore_completed.json',
            'verified_by' => 7,
        ], $service->summary()['restore_drill_evidence']);
    }

    public function testSummaryExposesIpGeoQueueResolverStatus(): void
    {
        $repository = new InMemoryIpGeoRepository();
        $startedAt = new DateTimeImmutable('2026-06-18T00:00:00+00:00');

        $repository->ensureQueued('203.0.113.100', 'Processing browser', 'CN', 'serving', $startedAt, 'req-processing');
        $repository->leasePending(1, $startedAt);

        $repository->ensureQueued('203.0.113.101', 'Old pending browser', 'CN', 'serving', $startedAt->modify('+1 minute'), 'req-pending-old');
        $repository->ensureQueued('203.0.113.102', 'New pending browser', 'US', 'serving', $startedAt->modify('+5 minutes'), 'req-pending-new');

        $repository->ensureQueued('203.0.113.103', 'Failed browser', 'SG', 'serving', $startedAt->modify('+2 minutes'), 'req-failed');
        $repository->markFailed('203.0.113.103', 'provider-failed', 'temporary provider failure', $startedAt->modify('+3 minutes'), 3, 120);

        $repository->ensureQueued('203.0.113.104', 'Dead browser', 'JP', 'operations_realtime_lookup', $startedAt->modify('+4 minutes'), 'req-dead');
        $repository->markFailed('203.0.113.104', 'provider-dead', 'permanent provider failure', $startedAt->modify('+6 minutes'), 1, 120);

        $repository->ensureQueued('203.0.113.105', 'Resolved browser', 'CN', 'serving', $startedAt->modify('+7 minutes'), 'req-resolved');
        $repository->markResolved(new GeoIpRecord(
            ipAddress: '203.0.113.105',
            countryCode: 'CN',
            countryName: 'China',
            regionCode: 'GD',
            regionName: 'Guangdong',
            cityName: 'Guangzhou',
            latitude: 23.1291,
            longitude: 113.2644,
            timezone: 'Asia/Shanghai',
            providerId: 'provider-resolved',
            resolvedAt: $startedAt->modify('+8 minutes'),
            rawPayloadHash: hash('sha256', '{"country_code":"CN"}'),
            rawPayloadSummary: ['country_code' => 'CN'],
        ));

        $service = new \VertoAD\Service\Operations\OperationsSummaryService(
            backupStatus: ['status' => 'healthy'],
            restoreDrillEvidence: ['last_drill_at' => null],
            redisHardeningInventory: ['password_configured' => true],
            ipGeoRepository: $repository,
        );

        $queue = $service->summary()['ip_geo_queue'] ?? null;

        self::assertIsArray($queue);
        self::assertSame([
            'pending' => 2,
            'processing' => 1,
            'failed' => 1,
            'dead' => 1,
            'resolved' => 1,
            'total' => 6,
        ], $queue['counts']);
        self::assertSame('2026-06-18T00:01:00+00:00', $queue['oldest_pending_at']);
        self::assertSame('2026-06-18T00:01:00+00:00', $queue['next_retry_at']);
        self::assertSame('dead', $queue['latest_failure']['status']);
        self::assertSame('provider-dead', $queue['latest_failure']['provider_id']);
        self::assertSame('permanent provider failure', $queue['latest_failure']['last_error']);
        self::assertSame(1, $queue['latest_failure']['attempts']);
        self::assertCount(5, $queue['recent_tasks']);
        self::assertContains('resolved', array_column($queue['recent_tasks'], 'status'));
    }

    public function testAppFactoryMergesRedisHardeningInventoryFromEnvironment(): void
    {
        $previous = $this->configureRedisHardeningEnvironment();

        try {
            $container = AppFactory::create()->getContainer();
            $service = $container?->get('VertoAD\\Service\\Operations\\OperationsSummaryService');

            self::assertIsObject($service);
            self::assertTrue(method_exists($service, 'summary'));

            $inventory = $this->value($service->summary(), 'redis_hardening_inventory');

            self::assertTrue($this->value($inventory, 'password_configured'));
            self::assertSame(['FLUSHALL', 'FLUSHDB', 'CONFIG'], $this->value($inventory, 'dangerous_commands_disabled'));
            self::assertSame('vertoad:staging:', $this->value($inventory, 'key_prefix'));
            self::assertSame('unknown', $this->value($inventory, 'prefix_collision_risk'));
            self::assertTrue($this->value($inventory, 'auth_failure_alerting_configured'));
        } finally {
            $this->restoreEnv($previous);
        }
    }

    /**
     * @return array<string, string|false>
     */
    private function configureRedisHardeningEnvironment(): array
    {
        $previous = [
            'APP_KEY' => getenv('APP_KEY'),
            'REDIS_PASSWORD' => getenv('REDIS_PASSWORD'),
            'REDIS_PREFIX' => getenv('REDIS_PREFIX'),
            'REDIS_DANGEROUS_COMMANDS_DISABLED' => getenv('REDIS_DANGEROUS_COMMANDS_DISABLED'),
            'REDIS_AUTH_FAILURE_ALERTING_CONFIGURED' => getenv('REDIS_AUTH_FAILURE_ALERTING_CONFIGURED'),
        ];

        putenv('APP_KEY=' . Key::createNewRandomKey()->saveToAsciiSafeString());
        putenv('REDIS_PASSWORD=unit-test-long-random-password');
        putenv('REDIS_PREFIX=vertoad:staging:');
        putenv('REDIS_DANGEROUS_COMMANDS_DISABLED=flushall, flushdb, config');
        putenv('REDIS_AUTH_FAILURE_ALERTING_CONFIGURED=true');

        return $previous;
    }

    /**
     * @param array<string, string|false> $previous
     */
    private function restoreEnv(array $previous): void
    {
        foreach ($previous as $name => $value) {
            if ($value === false) {
                putenv($name);

                continue;
            }

            putenv($name . '=' . $value);
        }
    }

    private function value(mixed $record, string $key): mixed
    {
        if (is_array($record)) {
            return $record[$key] ?? null;
        }

        if (is_object($record)) {
            return $record->{$key} ?? null;
        }

        return null;
    }

    private function backupJob(string $id, string $type, string $status, string $createdAt): BackupJob
    {
        $completedAt = $status === 'completed' ? new DateTimeImmutable($createdAt)->modify('+1 minute') : null;

        return new BackupJob(
            jobId: $id,
            jobType: $type,
            sourceBackupId: $type === 'restore' ? 'backup_completed' : null,
            status: $status,
            requestedByUserId: 7,
            requestId: 'req-' . $id,
            environment: 'staging',
            reason: $type === 'restore' ? 'Scheduled restore drill' : null,
            manifestObjectKey: $status === 'completed' ? 'backups/' . $id . '/manifest.json' : null,
            manifestSha256: $status === 'completed' ? str_repeat('b', 64) : null,
            mysqlObjectKey: $status === 'completed' ? 'backups/' . $id . '/mysql.sql' : null,
            mysqlSha256: $status === 'completed' ? str_repeat('a', 64) : null,
            configObjectKey: $status === 'completed' ? 'backups/' . $id . '/configuration.json' : null,
            evidenceObjectKey: $type === 'restore' && $status === 'completed'
                ? 'backups/restore-evidence/' . $id . '.json'
                : null,
            objectCount: 0,
            byteCount: 0,
            errorMessage: $status === 'failed' ? 'backup failed' : null,
            createdAt: new DateTimeImmutable($createdAt),
            startedAt: $status === 'queued' ? null : new DateTimeImmutable($createdAt),
            completedAt: $completedAt,
        );
    }
}
