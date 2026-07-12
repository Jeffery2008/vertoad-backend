<?php

declare(strict_types=1);

namespace VertoAD\Repository\Assets;

use DateTimeImmutable;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetSnapshotLease;

interface AssetSnapshotJobRepositoryInterface
{
    /** @return list<AssetSnapshotLease> */
    public function lease(int $limit, DateTimeImmutable $now, int $leaseSeconds): array;

    public function complete(
        AssetSnapshotLease $lease,
        AssetSnapshotArtifacts $artifacts,
        DateTimeImmutable $completedAt,
    ): void;

    public function fail(
        AssetSnapshotLease $lease,
        string $errorCode,
        string $errorMessage,
        bool $dead,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $failedAt,
    ): void;
}
