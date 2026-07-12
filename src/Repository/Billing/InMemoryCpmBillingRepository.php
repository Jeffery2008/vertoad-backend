<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use RuntimeException;
use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingAllocationStatus;
use VertoAD\Domain\Billing\CpmBillingClaim;
use VertoAD\Domain\Billing\CpmBillingStream;

final class InMemoryCpmBillingRepository implements CpmBillingRepositoryInterface
{
    /** @var array<string, CpmBillingAccumulator> */
    private array $accumulators = [];

    /** @var array<string, CpmBillingAllocation> */
    private array $allocations = [];

    private int $nextAllocationId = 1;

    public function transactional(callable $operation): mixed
    {
        $accumulators = $this->accumulators;
        $allocations = $this->allocations;
        $nextAllocationId = $this->nextAllocationId;

        try {
            return $operation();
        } catch (\Throwable $exception) {
            $this->accumulators = $accumulators;
            $this->allocations = $allocations;
            $this->nextAllocationId = $nextAllocationId;
            throw $exception;
        }
    }

    public function findAllocation(string $eventKey): ?CpmBillingAllocation
    {
        return $this->allocations[trim($eventKey)] ?? null;
    }

    public function findAccumulator(CpmBillingStream $stream): ?CpmBillingAccumulator
    {
        return $this->accumulators[$stream->key()] ?? null;
    }

    public function lockAccumulator(CpmBillingStream $stream): CpmBillingAccumulator
    {
        $key = $stream->key();
        if (!isset($this->accumulators[$key])) {
            $this->accumulators[$key] = new CpmBillingAccumulator(id: count($this->accumulators) + 1, stream: $stream);
        }

        return $this->accumulators[$key];
    }

    public function saveAccumulator(CpmBillingAccumulator $accumulator): CpmBillingAccumulator
    {
        if ($accumulator->version === PHP_INT_MAX) {
            throw new RuntimeException('CPM accumulator version has reached its integer limit.');
        }

        $key = $accumulator->stream->key();
        $current = $this->accumulators[$key] ?? null;
        if ($current === null) {
            throw new RuntimeException('CPM accumulator does not exist.');
        }
        if ($current->version !== $accumulator->version) {
            throw new RuntimeException('CPM accumulator version conflict.');
        }

        $saved = $accumulator->withVersion($accumulator->version + 1);
        $this->accumulators[$key] = $saved;

        return $saved;
    }

    public function claimAllocation(CpmBillingAllocation $allocation): CpmBillingClaim
    {
        if ($allocation->status !== CpmBillingAllocationStatus::Processing) {
            throw new RuntimeException('CPM allocation claim must have processing status.');
        }

        $existing = $this->findAllocation($allocation->eventKey);
        if ($existing !== null) {
            return new CpmBillingClaim($existing, acquired: false);
        }

        $stored = $allocation->withId($this->nextAllocationId++);
        $this->allocations[$stored->eventKey] = $stored;

        return new CpmBillingClaim($stored, acquired: true);
    }

    public function completeAllocation(CpmBillingAllocation $allocation): CpmBillingAllocation
    {
        if ($allocation->status === CpmBillingAllocationStatus::Processing) {
            throw new RuntimeException('CPM allocation completion must have a final status.');
        }

        $existing = $this->findAllocation($allocation->eventKey);
        if (
            $existing === null
            || $existing->status !== CpmBillingAllocationStatus::Processing
            || $existing->id !== $allocation->id
        ) {
            throw new RuntimeException('CPM allocation claim is not available for completion.');
        }

        $this->allocations[$allocation->eventKey] = $allocation;

        return $allocation;
    }

    /** @return list<CpmBillingAllocation> */
    public function allocations(): array
    {
        return array_values($this->allocations);
    }

    public function accumulatorFor(CpmBillingStream $stream): ?CpmBillingAccumulator
    {
        return $this->findAccumulator($stream);
    }
}
