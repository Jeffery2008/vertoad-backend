<?php

declare(strict_types=1);

namespace VertoAD\Domain\Assets;

use InvalidArgumentException;

final readonly class AssetSnapshotLease
{
    public function __construct(
        public int $jobId,
        public CreativeAsset $asset,
        public int $attempts,
        public string $leaseToken,
    ) {
        if (
            $this->jobId <= 0
            || $this->asset->id === null
            || $this->asset->id <= 0
            || $this->attempts <= 0
            || preg_match('/^[A-Za-z0-9_-]{16,64}$/D', $this->leaseToken) !== 1
        ) {
            throw new InvalidArgumentException('Asset snapshot lease identity is invalid.');
        }
    }
}
