<?php

declare(strict_types=1);

namespace VertoAD\Repository\Assets;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use RuntimeException;
use VertoAD\Domain\Assets\AssetObjectKey;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetSnapshotLease;
use VertoAD\Domain\Assets\AssetSnapshotStatus;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\CreativeAsset;

final class AssetSnapshotJobRepository implements AssetSnapshotJobRepositoryInterface
{
    /** @var callable(): string */
    private $leaseTokenGenerator;

    /** @param (callable(): string)|null $leaseTokenGenerator */
    public function __construct(
        private readonly Connection $connection,
        ?callable $leaseTokenGenerator = null,
    ) {
        $this->leaseTokenGenerator = $leaseTokenGenerator ?? static fn (): string => bin2hex(random_bytes(24));
    }

    public function lease(int $limit, DateTimeImmutable $now, int $leaseSeconds): array
    {
        if ($limit <= 0 || $leaseSeconds <= 0) {
            throw new \InvalidArgumentException('Asset snapshot lease limits must be positive.');
        }

        return $this->connection->transactional(function () use ($limit, $now, $leaseSeconds): array {
            $formattedNow = $this->formatDate($now);
            $this->connection->executeStatement(
                <<<'SQL'
UPDATE asset_snapshot_jobs
SET status = 'retry', lease_token = NULL, lease_expires_at = NULL, available_at = ?, updated_at = ?
WHERE status = 'processing' AND lease_expires_at IS NOT NULL AND lease_expires_at <= ?
SQL,
                [$formattedNow, $formattedNow, $formattedNow],
            );

            $rows = $this->connection->createQueryBuilder()
                ->select('j.id', 'j.status')
                ->from('asset_snapshot_jobs', 'j')
                ->innerJoin('j', 'creative_assets', 'a', 'a.id = j.asset_id AND a.organization_id = j.organization_id')
                ->where("j.status IN ('pending', 'retry')")
                ->andWhere('j.available_at <= :available_at')
                ->andWhere('a.snapshot_status = :snapshot_status')
                ->orderBy('j.available_at', 'ASC')
                ->addOrderBy('j.id', 'ASC')
                ->setParameter('available_at', $formattedNow)
                ->setParameter('snapshot_status', AssetSnapshotStatus::Pending->value)
                ->setMaxResults($limit)
                ->fetchAllAssociative();

            $leases = [];
            foreach ($rows as $row) {
                $jobId = (int) $row['id'];
                $status = (string) $row['status'];
                $leaseToken = ($this->leaseTokenGenerator)();
                if (!is_string($leaseToken) || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $leaseToken) !== 1) {
                    throw new RuntimeException('Asset snapshot lease token generator returned an invalid token.');
                }

                $leaseExpiresAt = $now->modify('+' . $leaseSeconds . ' seconds');
                $claimed = $this->connection->executeStatement(
                    <<<'SQL'
UPDATE asset_snapshot_jobs
SET status = 'processing', attempts = attempts + 1, lease_token = ?, lease_expires_at = ?, updated_at = ?
WHERE id = ? AND status = ? AND available_at <= ?
SQL,
                    [$leaseToken, $this->formatDate($leaseExpiresAt), $formattedNow, $jobId, $status, $formattedNow],
                );
                if ($claimed !== 1) {
                    continue;
                }

                $claimedRow = $this->claimedRow($jobId, $leaseToken);
                if ($claimedRow !== null) {
                    $leases[] = $this->hydrateLease($claimedRow);
                }
            }

            return $leases;
        });
    }

    public function complete(
        AssetSnapshotLease $lease,
        AssetSnapshotArtifacts $artifacts,
        DateTimeImmutable $completedAt,
    ): void {
        new AssetObjectKey($artifacts->pngObjectKey);
        new AssetObjectKey($artifacts->webpObjectKey);
        new AssetObjectKey($artifacts->thumbnailWebpObjectKey);

        $this->connection->transactional(function () use ($lease, $artifacts, $completedAt): void {
            $formattedCompletedAt = $this->formatDate($completedAt);
            $updatedJob = $this->connection->executeStatement(
                <<<'SQL'
UPDATE asset_snapshot_jobs
SET status = 'completed', lease_token = NULL, lease_expires_at = NULL,
    last_error_code = NULL, last_error_message = NULL, completed_at = ?, updated_at = ?
WHERE id = ? AND asset_id = ? AND status = 'processing' AND lease_token = ?
SQL,
                [$formattedCompletedAt, $formattedCompletedAt, $lease->jobId, $lease->asset->id, $lease->leaseToken],
            );
            if ($updatedJob !== 1) {
                throw new AssetSnapshotLeaseLostException('Asset snapshot lease is no longer active.');
            }

            $updatedAsset = $this->connection->update('creative_assets', [
                'snapshot_status' => AssetSnapshotStatus::Ready->value,
                'snapshot_png_object_key' => $artifacts->pngObjectKey,
                'snapshot_webp_object_key' => $artifacts->webpObjectKey,
                'thumbnail_webp_object_key' => $artifacts->thumbnailWebpObjectKey,
                'snapshot_completed_at' => $formattedCompletedAt,
            ], ['id' => $lease->asset->id, 'organization_id' => $lease->asset->organizationId]);
            if ($updatedAsset !== 1) {
                throw new RuntimeException('Asset snapshot completion could not update its creative asset.');
            }
        });
    }

    public function fail(
        AssetSnapshotLease $lease,
        string $errorCode,
        string $errorMessage,
        bool $dead,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $failedAt,
    ): void {
        $status = $dead ? 'dead' : 'retry';
        $assetStatus = $dead ? AssetSnapshotStatus::Failed : AssetSnapshotStatus::Pending;
        $errorCode = trim($errorCode);
        $errorMessage = trim($errorMessage);
        if (preg_match('/^[a-z0-9_]{1,80}$/D', $errorCode) !== 1 || $errorMessage === '') {
            throw new \InvalidArgumentException('Asset snapshot failure code and message are invalid.');
        }

        $this->connection->transactional(function () use (
            $lease,
            $errorCode,
            $errorMessage,
            $status,
            $assetStatus,
            $availableAt,
            $failedAt,
        ): void {
            $updatedJob = $this->connection->executeStatement(
                <<<'SQL'
UPDATE asset_snapshot_jobs
SET status = ?, lease_token = NULL, lease_expires_at = NULL, available_at = ?,
    last_error_code = ?, last_error_message = ?, completed_at = NULL, updated_at = ?
WHERE id = ? AND asset_id = ? AND status = 'processing' AND lease_token = ?
SQL,
                [
                    $status,
                    $this->formatDate($availableAt),
                    $errorCode,
                    substr($errorMessage, 0, 1000),
                    $this->formatDate($failedAt),
                    $lease->jobId,
                    $lease->asset->id,
                    $lease->leaseToken,
                ],
            );
            if ($updatedJob !== 1) {
                throw new AssetSnapshotLeaseLostException('Asset snapshot lease is no longer active.');
            }

            $updatedAsset = $this->connection->update(
                'creative_assets',
                ['snapshot_status' => $assetStatus->value],
                ['id' => $lease->asset->id, 'organization_id' => $lease->asset->organizationId],
            );
            if ($updatedAsset !== 1) {
                throw new RuntimeException('Asset snapshot failure could not update its creative asset.');
            }
        });
    }

    /** @return array<string, mixed>|null */
    private function claimedRow(int $jobId, string $leaseToken): ?array
    {
        $row = $this->connection->createQueryBuilder()
            ->select(
                'j.id AS job_id',
                'j.attempts',
                'j.lease_token',
                'a.id AS asset_id',
                'a.upload_intent_id',
                'a.organization_id',
                'a.uploader_user_id',
                'a.type',
                'a.object_key',
                'a.content_type',
                'a.byte_size',
                'a.width',
                'a.height',
                'a.duration_seconds',
                'a.checksum',
                'a.status AS asset_status',
                'a.snapshot_status',
                'a.snapshot_png_object_key',
                'a.snapshot_webp_object_key',
                'a.thumbnail_webp_object_key',
            )
            ->from('asset_snapshot_jobs', 'j')
            ->innerJoin('j', 'creative_assets', 'a', 'a.id = j.asset_id AND a.organization_id = j.organization_id')
            ->where('j.id = :job_id')
            ->andWhere('j.status = :status')
            ->andWhere('j.lease_token = :lease_token')
            ->setParameter('job_id', $jobId)
            ->setParameter('status', 'processing')
            ->setParameter('lease_token', $leaseToken)
            ->fetchAssociative();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $row */
    private function hydrateLease(array $row): AssetSnapshotLease
    {
        return new AssetSnapshotLease(
            jobId: (int) $row['job_id'],
            asset: new CreativeAsset(
                id: (int) $row['asset_id'],
                uploadIntentId: (int) $row['upload_intent_id'],
                organizationId: (int) $row['organization_id'],
                uploaderUserId: (int) $row['uploader_user_id'],
                type: AssetType::from((string) $row['type']),
                objectKey: (string) $row['object_key'],
                contentType: (string) $row['content_type'],
                byteSize: (int) $row['byte_size'],
                width: (int) $row['width'],
                height: (int) $row['height'],
                durationSeconds: $row['duration_seconds'] === null ? null : (float) $row['duration_seconds'],
                checksum: $row['checksum'] === null ? null : (string) $row['checksum'],
                status: AssetStatus::from((string) $row['asset_status']),
                snapshotStatus: AssetSnapshotStatus::from((string) $row['snapshot_status']),
                snapshotPngObjectKey: $row['snapshot_png_object_key'] === null ? null : (string) $row['snapshot_png_object_key'],
                snapshotWebpObjectKey: $row['snapshot_webp_object_key'] === null ? null : (string) $row['snapshot_webp_object_key'],
                thumbnailWebpObjectKey: $row['thumbnail_webp_object_key'] === null ? null : (string) $row['thumbnail_webp_object_key'],
            ),
            attempts: (int) $row['attempts'],
            leaseToken: (string) $row['lease_token'],
        );
    }

    private function formatDate(DateTimeImmutable $value): string
    {
        return $value->format('Y-m-d H:i:s');
    }
}
