<?php

declare(strict_types=1);

namespace VertoAD\Tests\Service\Assets;

use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use VertoAD\Domain\Assets\AssetSnapshotArtifacts;
use VertoAD\Domain\Assets\AssetSnapshotLease;
use VertoAD\Domain\Assets\AssetSnapshotStatus;
use VertoAD\Domain\Assets\AssetStatus;
use VertoAD\Domain\Assets\AssetType;
use VertoAD\Domain\Assets\CreativeAsset;
use VertoAD\Repository\Assets\AssetSnapshotJobRepositoryInterface;
use VertoAD\Repository\Assets\AssetSnapshotLeaseLostException;
use VertoAD\Service\Archive\ArchiveCommandResult;
use VertoAD\Service\Archive\ArchiveCommandRunnerInterface;
use VertoAD\Service\Assets\AssetObjectStorageInterface;
use VertoAD\Service\Assets\AssetSnapshotGenerator;
use VertoAD\Service\Assets\FabricCreativePayloadValidator;
use VertoAD\Service\Assets\FfmpegAssetFrameExtractor;
use VertoAD\Service\Cron\AssetSnapshotGenerationJob;
use VertoAD\Tests\Assets\AssetTestFixtures;

final class AssetSnapshotGenerationJobTest extends TestCase
{
    public function testValidatesLimitsAndReturnsStableIdentityForAnEmptyBatch(): void
    {
        $repository = new RecordingSnapshotJobRepository([]);
        $storage = new RecordingSnapshotObjectStorage([]);
        $generator = $this->generator();

        foreach ([
            [0, 1, 1, 1],
            [1, 0, 1, 1],
            [1, 1, 0, 1],
            [1, 1, 1, 0],
        ] as [$batchSize, $leaseSeconds, $maxAttempts, $backoff]) {
            try {
                new AssetSnapshotGenerationJob(
                    $repository,
                    $storage,
                    $generator,
                    $batchSize,
                    $leaseSeconds,
                    $maxAttempts,
                    $backoff,
                );
                self::fail('Expected invalid asset snapshot cron limits.');
            } catch (InvalidArgumentException $exception) {
                self::assertSame('Asset snapshot cron limits must be positive.', $exception->getMessage());
            }
        }

        $job = new AssetSnapshotGenerationJob($repository, $storage, $generator, 7, 90, 3, 30);
        $result = $job->run();

        self::assertSame('asset-snapshot-generate', $job->name());
        self::assertSame('completed', $result->status);
        self::assertSame(
            ['leased' => 0, 'completed' => 0, 'retried' => 0, 'dead' => 0, 'stale' => 0],
            $result->metrics,
        );
        self::assertSame('Asset snapshot generation processed the available durable jobs.', $result->message);
        self::assertSame(7, $repository->leaseLimit);
        self::assertSame(90, $repository->leaseSeconds);
        self::assertNotNull($repository->leasedAt);
    }

    public function testContinuesAfterPartialStorageFailureAndCompletesTheNextLease(): void
    {
        $now = new DateTimeImmutable('2026-07-11 10:00:00');
        $firstBody = AssetTestFixtures::image(width: 4, height: 3);
        $secondBody = AssetTestFixtures::image(width: 4, height: 3);
        $first = $this->lease(1, $this->asset(101, $firstBody), 1);
        $second = $this->lease(2, $this->asset(102, $secondBody), 1);
        $repository = new RecordingSnapshotJobRepository([$first, $second]);
        $storage = new RecordingSnapshotObjectStorage([
            $first->asset->objectKey => $firstBody,
            $second->asset->objectKey => $secondBody,
        ]);
        $storage->failPutCall = 2;

        $result = $this->job($repository, $storage, $now, backoff: 60)->run();

        self::assertSame(
            ['leased' => 2, 'completed' => 1, 'retried' => 1, 'dead' => 0, 'stale' => 0],
            $result->metrics,
        );
        self::assertCount(1, $repository->completed);
        self::assertSame(2, $repository->completed[0]['lease']->jobId);
        self::assertCount(1, $repository->failed);
        self::assertSame('asset_snapshot_storage_failed', $repository->failed[0]['error_code']);
        self::assertSame('Asset snapshot storage or processing failed unexpectedly.', $repository->failed[0]['error_message']);
        self::assertFalse($repository->failed[0]['dead']);
        self::assertSame('2026-07-11 10:01:00', $repository->failed[0]['available_at']->format('Y-m-d H:i:s'));
        self::assertCount(4, $storage->writes);
        self::assertSame('image/png', $storage->writes[0]['content_type']);
        self::assertSame(
            ['image/png', 'image/webp', 'image/webp'],
            array_column(array_slice($storage->writes, 1), 'content_type'),
        );
    }

    public function testAppliesRetryabilityAttemptLimitsAndExponentialBackoff(): void
    {
        $now = new DateTimeImmutable('2026-07-11 11:00:00');
        $body = AssetTestFixtures::image();
        $retryable = $this->lease(11, $this->asset(111, $body), 1);
        $changed = $this->lease(12, $this->asset(112, $body, 'sha256:' . str_repeat('0', 64)), 1);
        $exhausted = $this->lease(13, $this->asset(113, $body), 3);
        $unexpected = $this->lease(14, $this->asset(114, $body), 3);
        $repository = new RecordingSnapshotJobRepository([$retryable, $changed, $exhausted, $unexpected]);
        $storage = new RecordingSnapshotObjectStorage([
            $retryable->asset->objectKey => '',
            $changed->asset->objectKey => $body,
            $exhausted->asset->objectKey => '',
            $unexpected->asset->objectKey => $body,
        ]);
        $storage->readFailures[$unexpected->asset->objectKey] = new RuntimeException('provider unavailable');

        $result = $this->job($repository, $storage, $now, maxAttempts: 3, backoff: 15)->run();

        self::assertSame(
            ['leased' => 4, 'completed' => 0, 'retried' => 1, 'dead' => 3, 'stale' => 0],
            $result->metrics,
        );
        self::assertSame(
            [
                ['asset_snapshot_source_missing', false],
                ['asset_snapshot_source_checksum_changed', true],
                ['asset_snapshot_source_missing', true],
                ['asset_snapshot_storage_failed', true],
            ],
            array_map(
                static fn (array $failure): array => [$failure['error_code'], $failure['dead']],
                $repository->failed,
            ),
        );
        self::assertSame('2026-07-11 11:00:15', $repository->failed[0]['available_at']->format('Y-m-d H:i:s'));
        foreach (array_slice($repository->failed, 1) as $failure) {
            self::assertSame($now, $failure['available_at']);
            self::assertSame($now, $failure['failed_at']);
        }
    }

    public function testCountsLeaseLossAtCompletionAndBothFailureBoundaries(): void
    {
        $now = new DateTimeImmutable('2026-07-11 12:00:00');
        $body = AssetTestFixtures::image();
        $completion = $this->lease(21, $this->asset(121, $body), 1);
        $processingFailure = $this->lease(22, $this->asset(122, $body), 1);
        $unexpectedFailure = $this->lease(23, $this->asset(123, $body), 1);
        $repository = new RecordingSnapshotJobRepository([$completion, $processingFailure, $unexpectedFailure]);
        $repository->completeLeaseLosses[21] = true;
        $repository->failLeaseLosses[22] = true;
        $repository->failLeaseLosses[23] = true;
        $storage = new RecordingSnapshotObjectStorage([
            $completion->asset->objectKey => $body,
            $processingFailure->asset->objectKey => '',
            $unexpectedFailure->asset->objectKey => $body,
        ]);
        $storage->readFailures[$unexpectedFailure->asset->objectKey] = new RuntimeException('read failed');

        $result = $this->job($repository, $storage, $now)->run();

        self::assertSame(
            ['leased' => 3, 'completed' => 0, 'retried' => 0, 'dead' => 0, 'stale' => 3],
            $result->metrics,
        );
        self::assertCount(3, $storage->writes);
        self::assertSame([], $repository->completed);
        self::assertSame([], $repository->failed);
    }

    public function testCapsLargeRetryDelayAtOneDay(): void
    {
        $now = new DateTimeImmutable('2026-07-11 13:00:00');
        $body = AssetTestFixtures::image();
        $lease = $this->lease(31, $this->asset(131, $body), 12);
        $repository = new RecordingSnapshotJobRepository([$lease]);
        $storage = new RecordingSnapshotObjectStorage([$lease->asset->objectKey => '']);

        $result = $this->job(
            $repository,
            $storage,
            $now,
            maxAttempts: 20,
            backoff: 100_000,
        )->run();

        self::assertSame(1, $result->metrics['retried']);
        self::assertSame('2026-07-12 13:00:00', $repository->failed[0]['available_at']->format('Y-m-d H:i:s'));
    }

    private function job(
        RecordingSnapshotJobRepository $repository,
        RecordingSnapshotObjectStorage $storage,
        DateTimeImmutable $now,
        int $maxAttempts = 3,
        int $backoff = 30,
    ): AssetSnapshotGenerationJob {
        return new AssetSnapshotGenerationJob(
            jobs: $repository,
            storage: $storage,
            generator: $this->generator(),
            batchSize: 25,
            leaseSeconds: 300,
            maxAttempts: $maxAttempts,
            retryBackoffSeconds: $backoff,
            clock: static fn (): DateTimeImmutable => $now,
        );
    }

    private function generator(): AssetSnapshotGenerator
    {
        return new AssetSnapshotGenerator(
            new FabricCreativePayloadValidator(),
            new FfmpegAssetFrameExtractor(new SnapshotJobFrameRunner(), 'ffmpeg-test', 5),
        );
    }

    private function asset(int $id, string $body, ?string $checksum = null): CreativeAsset
    {
        return new CreativeAsset(
            id: $id,
            uploadIntentId: $id + 1_000,
            organizationId: 99,
            uploaderUserId: 5,
            type: AssetType::Image,
            objectKey: 'organizations/99/assets/source-' . $id . '.png',
            contentType: 'image/png',
            byteSize: strlen($body),
            width: 4,
            height: 3,
            durationSeconds: null,
            checksum: $checksum ?? 'sha256:' . hash('sha256', $body),
            status: AssetStatus::PendingReview,
            snapshotStatus: AssetSnapshotStatus::Pending,
        );
    }

    private function lease(int $jobId, CreativeAsset $asset, int $attempts): AssetSnapshotLease
    {
        return new AssetSnapshotLease($jobId, $asset, $attempts, 'snapshot-job-token-' . $jobId);
    }
}

final class RecordingSnapshotJobRepository implements AssetSnapshotJobRepositoryInterface
{
    public ?int $leaseLimit = null;
    public ?DateTimeImmutable $leasedAt = null;
    public ?int $leaseSeconds = null;

    /** @var list<array{lease:AssetSnapshotLease, artifacts:AssetSnapshotArtifacts, completed_at:DateTimeImmutable}> */
    public array $completed = [];

    /** @var list<array{lease:AssetSnapshotLease, error_code:string, error_message:string, dead:bool, available_at:DateTimeImmutable, failed_at:DateTimeImmutable}> */
    public array $failed = [];

    /** @var array<int, bool> */
    public array $completeLeaseLosses = [];

    /** @var array<int, bool> */
    public array $failLeaseLosses = [];

    /** @param list<AssetSnapshotLease> $leases */
    public function __construct(private readonly array $leases)
    {
    }

    public function lease(int $limit, DateTimeImmutable $now, int $leaseSeconds): array
    {
        $this->leaseLimit = $limit;
        $this->leasedAt = $now;
        $this->leaseSeconds = $leaseSeconds;

        return $this->leases;
    }

    public function complete(
        AssetSnapshotLease $lease,
        AssetSnapshotArtifacts $artifacts,
        DateTimeImmutable $completedAt,
    ): void {
        if (isset($this->completeLeaseLosses[$lease->jobId])) {
            throw new AssetSnapshotLeaseLostException('injected completion lease loss');
        }
        $this->completed[] = ['lease' => $lease, 'artifacts' => $artifacts, 'completed_at' => $completedAt];
    }

    public function fail(
        AssetSnapshotLease $lease,
        string $errorCode,
        string $errorMessage,
        bool $dead,
        DateTimeImmutable $availableAt,
        DateTimeImmutable $failedAt,
    ): void {
        if (isset($this->failLeaseLosses[$lease->jobId])) {
            throw new AssetSnapshotLeaseLostException('injected failure lease loss');
        }
        $this->failed[] = [
            'lease' => $lease,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'dead' => $dead,
            'available_at' => $availableAt,
            'failed_at' => $failedAt,
        ];
    }
}

final class RecordingSnapshotObjectStorage implements AssetObjectStorageInterface
{
    /** @var list<string> */
    public array $reads = [];

    /** @var list<array{object_key:string, body:string, content_type:string}> */
    public array $writes = [];

    /** @var array<string, \Throwable> */
    public array $readFailures = [];

    public ?int $failPutCall = null;
    private int $putCalls = 0;

    /** @param array<string, string> $bodies */
    public function __construct(private readonly array $bodies)
    {
    }

    public function read(string $objectKey): string
    {
        $this->reads[] = $objectKey;
        if (isset($this->readFailures[$objectKey])) {
            throw $this->readFailures[$objectKey];
        }

        return $this->bodies[$objectKey] ?? '';
    }

    public function putSnapshot(string $objectKey, string $body, string $contentType): void
    {
        ++$this->putCalls;
        if ($this->failPutCall === $this->putCalls) {
            $this->failPutCall = null;
            throw new RuntimeException('injected snapshot write failure');
        }
        $this->writes[] = ['object_key' => $objectKey, 'body' => $body, 'content_type' => $contentType];
    }
}

final class SnapshotJobFrameRunner implements ArchiveCommandRunnerInterface
{
    public function run(array $command, ?string $stdin, int $timeoutSeconds): ArchiveCommandResult
    {
        return new ArchiveCommandResult(0, AssetTestFixtures::image(), '');
    }
}
