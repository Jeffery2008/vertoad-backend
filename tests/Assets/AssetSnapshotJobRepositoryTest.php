<?php

declare(strict_types=1);

namespace VertoAD\Tests\Assets;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetSnapshotLease;
use VertoAD\Repository\Assets\AssetSnapshotJobRepository;
use VertoAD\Repository\Assets\AssetSnapshotLeaseLostException;

final class AssetSnapshotJobRepositoryTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        AssetSchema::create($this->connection);
    }

    protected function tearDown(): void
    {
        $this->connection->close();
    }

    public function testLeasesEligibleJobsInStableOrderAndReclaimsExpiredWork(): void
    {
        $now = new DateTimeImmutable('2026-07-11 02:00:00');
        $first = $this->insertAssetAndJob(availableAt: '2026-07-11 01:00:00');
        $second = $this->insertAssetAndJob(
            availableAt: '2026-07-11 01:30:00',
            jobStatus: 'processing',
            attempts: 2,
            leaseToken: 'expired-lease-token',
            leaseExpiresAt: '2026-07-11 01:59:59',
            duration: 3.25,
            derivedKeys: true,
        );
        $this->insertAssetAndJob(availableAt: '2026-07-11 03:00:00');
        $this->insertAssetAndJob(availableAt: '2026-07-11 01:00:00', snapshotStatus: 'ready');
        $tokens = ['lease-token-first1', 'lease-token-second'];
        $repository = new AssetSnapshotJobRepository(
            $this->connection,
            static fn (): string => array_shift($tokens) ?? 'lease-token-fallback',
        );

        $leases = $repository->lease(2, $now, 45);

        self::assertCount(2, $leases);
        self::assertSame([$first['job_id'], $second['job_id']], array_map(static fn (AssetSnapshotLease $lease): int => $lease->jobId, $leases));
        self::assertSame(1, $leases[0]->attempts);
        self::assertSame(3, $leases[1]->attempts);
        self::assertNull($leases[0]->asset->durationSeconds);
        self::assertSame(3.25, $leases[1]->asset->durationSeconds);
        self::assertNull($leases[0]->asset->snapshotPngObjectKey);
        self::assertNotNull($leases[1]->asset->snapshotPngObjectKey);
        self::assertSame('2026-07-11 02:00:45', $this->connection->fetchOne(
            'SELECT lease_expires_at FROM asset_snapshot_jobs WHERE id = ?',
            [$first['job_id']],
        ));
    }

    public function testLeaseSkipsClaimLostToAConcurrentWorkerAndMissingClaimedAsset(): void
    {
        $raced = $this->insertAssetAndJob();
        $repository = new AssetSnapshotJobRepository($this->connection, function () use ($raced): string {
            $this->connection->update('asset_snapshot_jobs', ['status' => 'completed'], ['id' => $raced['job_id']]);

            return 'race-lost-token1';
        });
        self::assertSame([], $repository->lease(1, new DateTimeImmutable('2026-07-11 02:00:00'), 30));

        $missing = $this->insertAssetAndJob();
        $this->connection->executeStatement(sprintf(
            "CREATE TRIGGER remove_claimed_asset AFTER UPDATE OF status ON asset_snapshot_jobs
             WHEN NEW.id = %d AND NEW.status = 'processing'
             BEGIN DELETE FROM creative_assets WHERE id = NEW.asset_id; END",
            $missing['job_id'],
        ));
        $repository = new AssetSnapshotJobRepository($this->connection, static fn (): string => 'missing-asset-token');
        self::assertSame([], $repository->lease(1, new DateTimeImmutable('2026-07-11 02:00:00'), 30));
    }

    public function testDefaultTokenGeneratorProducesAValidLease(): void
    {
        $this->insertAssetAndJob();

        $leases = (new AssetSnapshotJobRepository($this->connection))->lease(
            1,
            new DateTimeImmutable('2026-07-11 02:00:00'),
            30,
        );

        self::assertCount(1, $leases);
        self::assertMatchesRegularExpression('/^[a-f0-9]{48}$/D', $leases[0]->leaseToken);
    }

    public function testLeaseRejectsInvalidLimitsAndGeneratedTokens(): void
    {
        $repository = new AssetSnapshotJobRepository($this->connection);
        foreach ([[0, 1], [1, 0]] as [$limit, $seconds]) {
            try {
                $repository->lease($limit, new DateTimeImmutable(), $seconds);
                self::fail('Expected invalid lease limits.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset snapshot lease limits must be positive.', $exception->getMessage());
            }
        }

        foreach ([static fn (): int => 42, static fn (): string => 'bad token'] as $generator) {
            $this->insertAssetAndJob();
            try {
                (new AssetSnapshotJobRepository($this->connection, $generator))->lease(
                    1,
                    new DateTimeImmutable('2026-07-11 02:00:00'),
                    30,
                );
                self::fail('Expected invalid generated token.');
            } catch (RuntimeException $exception) {
                self::assertSame('Asset snapshot lease token generator returned an invalid token.', $exception->getMessage());
            }
            $this->connection->executeStatement("UPDATE asset_snapshot_jobs SET status = 'completed'");
        }
    }

    public function testCompletesLeaseAndRejectsLostOrOrphanedCompletion(): void
    {
        $lease = $this->leaseOne();
        $artifacts = $this->artifacts();
        $repository = new AssetSnapshotJobRepository($this->connection);
        $completedAt = new DateTimeImmutable('2026-07-11 03:04:05');

        $repository->complete($lease, $artifacts, $completedAt);

        $job = $this->connection->fetchAssociative('SELECT * FROM asset_snapshot_jobs WHERE id = ?', [$lease->jobId]);
        $asset = $this->connection->fetchAssociative('SELECT * FROM creative_assets WHERE id = ?', [$lease->asset->id]);
        self::assertSame('completed', $job['status']);
        self::assertNull($job['lease_token']);
        self::assertSame('2026-07-11 03:04:05', $job['completed_at']);
        self::assertSame('ready', $asset['snapshot_status']);
        self::assertSame($artifacts->pngObjectKey, $asset['snapshot_png_object_key']);

        $this->expectException(AssetSnapshotLeaseLostException::class);
        $repository->complete($lease, $artifacts, $completedAt);
    }

    public function testCompletionRollsBackWhenCreativeAssetCannotBeUpdated(): void
    {
        $lease = $this->leaseOne();
        $this->connection->delete('creative_assets', ['id' => $lease->asset->id]);

        try {
            (new AssetSnapshotJobRepository($this->connection))->complete(
                $lease,
                $this->artifacts(),
                new DateTimeImmutable('2026-07-11 03:04:05'),
            );
            self::fail('Expected orphaned completion failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Asset snapshot completion could not update its creative asset.', $exception->getMessage());
        }

        self::assertSame('processing', $this->connection->fetchOne(
            'SELECT status FROM asset_snapshot_jobs WHERE id = ?',
            [$lease->jobId],
        ));
    }

    public function testRecordsRetryAndDeadFailuresWithBoundedMessages(): void
    {
        $repository = new AssetSnapshotJobRepository($this->connection);
        $retry = $this->leaseOne();
        $repository->fail(
            $retry,
            'temporary_failure',
            str_repeat('x', 1_100),
            false,
            new DateTimeImmutable('2026-07-11 03:10:00'),
            new DateTimeImmutable('2026-07-11 03:00:00'),
        );
        $row = $this->connection->fetchAssociative('SELECT * FROM asset_snapshot_jobs WHERE id = ?', [$retry->jobId]);
        self::assertSame('retry', $row['status']);
        self::assertSame(1_000, strlen((string) $row['last_error_message']));
        self::assertNull($row['completed_at']);
        self::assertSame('pending', $this->connection->fetchOne('SELECT snapshot_status FROM creative_assets WHERE id = ?', [$retry->asset->id]));

        $dead = $this->leaseOne();
        $repository->fail(
            $dead,
            'permanent_failure',
            'cannot render',
            true,
            new DateTimeImmutable('2026-07-11 04:00:00'),
            new DateTimeImmutable('2026-07-11 04:00:00'),
        );
        self::assertSame('dead', $this->connection->fetchOne('SELECT status FROM asset_snapshot_jobs WHERE id = ?', [$dead->jobId]));
        self::assertSame('failed', $this->connection->fetchOne('SELECT snapshot_status FROM creative_assets WHERE id = ?', [$dead->asset->id]));
    }

    public function testFailureRejectsInvalidDetailsAndLostOrOrphanedLeases(): void
    {
        $repository = new AssetSnapshotJobRepository($this->connection);
        $lease = $this->leaseOne();
        foreach ([['Bad-Code', 'message'], ['valid_code', '   ']] as [$code, $message]) {
            try {
                $repository->fail($lease, $code, $message, false, new DateTimeImmutable(), new DateTimeImmutable());
                self::fail('Expected invalid failure details.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset snapshot failure code and message are invalid.', $exception->getMessage());
            }
        }

        $this->connection->update('asset_snapshot_jobs', ['lease_token' => 'different-lease-token'], ['id' => $lease->jobId]);
        try {
            $repository->fail($lease, 'valid_code', 'message', false, new DateTimeImmutable(), new DateTimeImmutable());
            self::fail('Expected lost lease.');
        } catch (AssetSnapshotLeaseLostException) {
            self::assertTrue(true);
        }

        $orphan = $this->leaseOne();
        $this->connection->delete('creative_assets', ['id' => $orphan->asset->id]);
        try {
            $repository->fail($orphan, 'valid_code', 'message', true, new DateTimeImmutable(), new DateTimeImmutable());
            self::fail('Expected orphaned failure.');
        } catch (RuntimeException $exception) {
            self::assertSame('Asset snapshot failure could not update its creative asset.', $exception->getMessage());
        }
        self::assertSame('processing', $this->connection->fetchOne('SELECT status FROM asset_snapshot_jobs WHERE id = ?', [$orphan->jobId]));
    }

    /** @return array{asset_id:int, job_id:int} */
    private function insertAssetAndJob(
        string $availableAt = '2026-07-11 01:00:00',
        string $jobStatus = 'pending',
        int $attempts = 0,
        ?string $leaseToken = null,
        ?string $leaseExpiresAt = null,
        string $snapshotStatus = 'pending',
        ?float $duration = null,
        bool $derivedKeys = false,
    ): array {
        $suffix = (int) $this->connection->fetchOne('SELECT COUNT(*) FROM creative_assets') + 1;
        $body = AssetTestFixtures::image();
        $this->connection->insert('asset_upload_intents', [
            'organization_id' => 99,
            'uploader_user_id' => 5,
            'type' => 'image',
            'original_filename' => 'source.png',
            'object_key' => "organizations/99/assets/source-{$suffix}.png",
            'content_type' => 'image/png',
            'byte_size' => strlen($body),
            'status' => 'confirmed',
            'expires_at' => '2026-07-12 00:00:00',
        ]);
        $intentId = (int) $this->connection->lastInsertId();
        $this->connection->insert('creative_assets', [
            'upload_intent_id' => $intentId,
            'organization_id' => 99,
            'uploader_user_id' => 5,
            'type' => 'image',
            'object_key' => "organizations/99/assets/source-{$suffix}.png",
            'content_type' => 'image/png',
            'byte_size' => strlen($body),
            'width' => 4,
            'height' => 3,
            'duration_seconds' => $duration,
            'checksum' => $derivedKeys ? null : 'sha256:' . hash('sha256', $body),
            'status' => 'pending_review',
            'snapshot_status' => $snapshotStatus,
            'snapshot_png_object_key' => $derivedKeys ? "organizations/99/assets/derived/{$suffix}/snapshot.png" : null,
            'snapshot_webp_object_key' => $derivedKeys ? "organizations/99/assets/derived/{$suffix}/snapshot.webp" : null,
            'thumbnail_webp_object_key' => $derivedKeys ? "organizations/99/assets/derived/{$suffix}/thumbnail.webp" : null,
        ]);
        $assetId = (int) $this->connection->lastInsertId();
        $this->connection->insert('asset_snapshot_jobs', [
            'asset_id' => $assetId,
            'organization_id' => 99,
            'status' => $jobStatus,
            'attempts' => $attempts,
            'available_at' => $availableAt,
            'lease_token' => $leaseToken,
            'lease_expires_at' => $leaseExpiresAt,
        ]);

        return ['asset_id' => $assetId, 'job_id' => (int) $this->connection->lastInsertId()];
    }

    private function leaseOne(): AssetSnapshotLease
    {
        $this->insertAssetAndJob();
        $leases = (new AssetSnapshotJobRepository(
            $this->connection,
            static fn (): string => 'repository-test-token',
        ))->lease(1, new DateTimeImmutable('2026-07-11 02:00:00'), 60);
        self::assertCount(1, $leases);

        return $leases[0];
    }

    private function artifacts(): AssetSnapshotArtifacts
    {
        $png = AssetTestFixtures::image();
        $webp = AssetTestFixtures::image('image/webp');

        return new AssetSnapshotArtifacts(
            'organizations/99/assets/derived/11/snapshot.png',
            'organizations/99/assets/derived/11/snapshot.webp',
            'organizations/99/assets/derived/11/thumbnail.webp',
            $png,
            $webp,
            $webp,
            4,
            3,
            4,
            3,
        );
    }
}
