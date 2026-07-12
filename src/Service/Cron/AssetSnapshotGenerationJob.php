<?php

declare(strict_types=1);

namespace VertoAD\Service\Cron;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;
use VertoAD\Domain\Cron\CronJobResult;
use VertoAD\Repository\Assets\AssetSnapshotJobRepositoryInterface;
use VertoAD\Repository\Assets\AssetSnapshotLeaseLostException;
use VertoAD\Service\Assets\AssetObjectStorageInterface;
use VertoAD\Service\Assets\AssetSnapshotGenerator;
use VertoAD\Service\Assets\AssetSnapshotProcessingException;

final class AssetSnapshotGenerationJob implements CronJobInterface
{
    /** @var callable(): DateTimeImmutable */
    private $clock;

    /** @param (callable(): DateTimeImmutable)|null $clock */
    public function __construct(
        private readonly AssetSnapshotJobRepositoryInterface $jobs,
        private readonly AssetObjectStorageInterface $storage,
        private readonly AssetSnapshotGenerator $generator,
        private readonly int $batchSize,
        private readonly int $leaseSeconds,
        private readonly int $maxAttempts,
        private readonly int $retryBackoffSeconds,
        ?callable $clock = null,
    ) {
        if (
            $this->batchSize <= 0
            || $this->leaseSeconds <= 0
            || $this->maxAttempts <= 0
            || $this->retryBackoffSeconds <= 0
        ) {
            throw new \InvalidArgumentException('Asset snapshot cron limits must be positive.');
        }

        $this->clock = $clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function name(): string
    {
        return 'asset-snapshot-generate';
    }

    public function run(): CronJobResult
    {
        $startedAt = ($this->clock)();
        $leases = $this->jobs->lease($this->batchSize, $startedAt, $this->leaseSeconds);
        $completed = 0;
        $retried = 0;
        $dead = 0;
        $stale = 0;

        foreach ($leases as $lease) {
            try {
                $sourceBody = $this->storage->read($lease->asset->objectKey);
                $artifacts = $this->generator->generate($lease->asset, $sourceBody);
                $this->storage->putSnapshot($artifacts->pngObjectKey, $artifacts->pngBytes, 'image/png');
                $this->storage->putSnapshot($artifacts->webpObjectKey, $artifacts->webpBytes, 'image/webp');
                $this->storage->putSnapshot(
                    $artifacts->thumbnailWebpObjectKey,
                    $artifacts->thumbnailWebpBytes,
                    'image/webp',
                );
                $this->jobs->complete($lease, $artifacts, ($this->clock)());
                ++$completed;
            } catch (AssetSnapshotLeaseLostException) {
                ++$stale;
            } catch (AssetSnapshotProcessingException $exception) {
                $isDead = !$exception->retryable || $lease->attempts >= $this->maxAttempts;
                $failedAt = ($this->clock)();
                try {
                    $this->jobs->fail(
                        $lease,
                        $exception->errorCode,
                        $exception->getMessage(),
                        $isDead,
                        $isDead ? $failedAt : $this->retryAt($failedAt, $lease->attempts),
                        $failedAt,
                    );
                    $isDead ? ++$dead : ++$retried;
                } catch (AssetSnapshotLeaseLostException) {
                    ++$stale;
                }
            } catch (Throwable) {
                $isDead = $lease->attempts >= $this->maxAttempts;
                $failedAt = ($this->clock)();
                try {
                    $this->jobs->fail(
                        $lease,
                        'asset_snapshot_storage_failed',
                        'Asset snapshot storage or processing failed unexpectedly.',
                        $isDead,
                        $isDead ? $failedAt : $this->retryAt($failedAt, $lease->attempts),
                        $failedAt,
                    );
                    $isDead ? ++$dead : ++$retried;
                } catch (AssetSnapshotLeaseLostException) {
                    ++$stale;
                }
            }
        }

        return CronJobResult::completed($this->name(), [
            'leased' => count($leases),
            'completed' => $completed,
            'retried' => $retried,
            'dead' => $dead,
            'stale' => $stale,
        ], 'Asset snapshot generation processed the available durable jobs.');
    }

    private function retryAt(DateTimeImmutable $now, int $attempts): DateTimeImmutable
    {
        $exponent = max(0, min(10, $attempts - 1));
        $delay = min(86_400, $this->retryBackoffSeconds * (2 ** $exponent));

        return $now->modify('+' . $delay . ' seconds');
    }
}
