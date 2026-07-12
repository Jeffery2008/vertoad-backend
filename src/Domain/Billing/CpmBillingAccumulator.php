<?php

declare(strict_types=1);

namespace VertoAD\Domain\Billing;

use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

final readonly class CpmBillingAccumulator
{
    public const int PUBLISHER_SHARE_DENOMINATOR = 10_000_000;

    public function __construct(
        public ?int $id,
        public CpmBillingStream $stream,
        public int $grossRemainderMilliPoints = 0,
        public int $publisherShareRemainderNumerator = 0,
        public int $impressionCount = 0,
        public int $billedPoints = 0,
        public int $publisherPoints = 0,
        public int $version = 0,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
        if ($this->id !== null && $this->id <= 0) {
            throw new InvalidArgumentException('CPM billing accumulator ID must be positive when provided.');
        }
        if ($this->grossRemainderMilliPoints < 0 || $this->grossRemainderMilliPoints >= 1_000) {
            throw new InvalidArgumentException('CPM gross remainder must be between 0 and 999.');
        }
        if (
            $this->publisherShareRemainderNumerator < 0
            || $this->publisherShareRemainderNumerator >= self::PUBLISHER_SHARE_DENOMINATOR
        ) {
            throw new InvalidArgumentException('CPM publisher share remainder numerator is invalid.');
        }
        if ($this->impressionCount < 0 || $this->billedPoints < 0 || $this->publisherPoints < 0 || $this->version < 0) {
            throw new InvalidArgumentException('CPM accumulator counters cannot be negative.');
        }
        if ($this->publisherPoints > $this->billedPoints) {
            throw new InvalidArgumentException('CPM publisher points cannot exceed billed points.');
        }
    }

    public function allocate(int $bidPointsPerThousand, int $shareRatioBps): CpmBillingAllocationCalculation
    {
        if ($bidPointsPerThousand <= 0) {
            throw new InvalidArgumentException('CPM bid points per thousand must be positive.');
        }
        if ($bidPointsPerThousand > PHP_INT_MAX - $this->grossRemainderMilliPoints) {
            throw new RuntimeException('CPM bid points exceed the supported integer range.');
        }
        if ($shareRatioBps < 0 || $shareRatioBps > 10_000) {
            throw new InvalidArgumentException('CPM publisher share ratio must be between 0 and 10000 basis points.');
        }
        if ($this->impressionCount === PHP_INT_MAX) {
            throw new RuntimeException('CPM impression accumulator has reached its integer limit.');
        }

        $grossRemainderBefore = $this->grossRemainderMilliPoints;
        $grossNumerator = $grossRemainderBefore + $bidPointsPerThousand;
        $grossPoints = intdiv($grossNumerator, 1_000);
        $grossRemainderAfter = $grossNumerator % 1_000;

        $publisherShareRemainderBefore = $this->publisherShareRemainderNumerator;
        if (
            $shareRatioBps > 0
            && $bidPointsPerThousand > intdiv(PHP_INT_MAX - $publisherShareRemainderBefore, $shareRatioBps)
        ) {
            throw new RuntimeException('CPM publisher share numerator exceeds the supported integer range.');
        }
        $publisherShareNumerator = $publisherShareRemainderBefore + ($bidPointsPerThousand * $shareRatioBps);
        $publisherPoints = intdiv($publisherShareNumerator, self::PUBLISHER_SHARE_DENOMINATOR);
        $publisherShareRemainderAfter = $publisherShareNumerator % self::PUBLISHER_SHARE_DENOMINATOR;

        if ($grossPoints > PHP_INT_MAX - $this->billedPoints) {
            throw new RuntimeException('CPM billed points accumulator has reached its integer limit.');
        }
        if ($publisherPoints > PHP_INT_MAX - $this->publisherPoints) {
            throw new RuntimeException('CPM publisher points accumulator has reached its integer limit.');
        }
        $nextAccumulator = new self(
            id: $this->id,
            stream: $this->stream,
            grossRemainderMilliPoints: $grossRemainderAfter,
            publisherShareRemainderNumerator: $publisherShareRemainderAfter,
            impressionCount: $this->impressionCount + 1,
            billedPoints: $this->billedPoints + $grossPoints,
            publisherPoints: $this->publisherPoints + $publisherPoints,
            version: $this->version,
            updatedAt: $this->updatedAt,
        );

        return new CpmBillingAllocationCalculation(
            nextAccumulator: $nextAccumulator,
            bidPointsPerThousand: $bidPointsPerThousand,
            grossRemainderBefore: $grossRemainderBefore,
            grossPoints: $grossPoints,
            grossRemainderAfter: $grossRemainderAfter,
            publisherShareRemainderBefore: $publisherShareRemainderBefore,
            publisherPoints: $publisherPoints,
            publisherShareRemainderAfter: $publisherShareRemainderAfter,
            platformPoints: $grossPoints - $publisherPoints,
        );
    }

    public function withVersion(int $version, ?DateTimeImmutable $updatedAt = null): self
    {
        return new self(
            id: $this->id,
            stream: $this->stream,
            grossRemainderMilliPoints: $this->grossRemainderMilliPoints,
            publisherShareRemainderNumerator: $this->publisherShareRemainderNumerator,
            impressionCount: $this->impressionCount,
            billedPoints: $this->billedPoints,
            publisherPoints: $this->publisherPoints,
            version: $version,
            updatedAt: $updatedAt ?? $this->updatedAt,
        );
    }
}
