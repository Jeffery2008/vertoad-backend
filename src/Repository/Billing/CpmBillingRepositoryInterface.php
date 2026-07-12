<?php

declare(strict_types=1);

namespace VertoAD\Repository\Billing;

use VertoAD\Domain\Billing\CpmBillingAccumulator;
use VertoAD\Domain\Billing\CpmBillingAllocation;
use VertoAD\Domain\Billing\CpmBillingClaim;
use VertoAD\Domain\Billing\CpmBillingStream;

interface CpmBillingRepositoryInterface
{
    public function transactional(callable $operation): mixed;

    public function findAllocation(string $eventKey): ?CpmBillingAllocation;

    public function findAccumulator(CpmBillingStream $stream): ?CpmBillingAccumulator;

    public function lockAccumulator(CpmBillingStream $stream): CpmBillingAccumulator;

    public function saveAccumulator(CpmBillingAccumulator $accumulator): CpmBillingAccumulator;

    public function claimAllocation(CpmBillingAllocation $allocation): CpmBillingClaim;

    public function completeAllocation(CpmBillingAllocation $allocation): CpmBillingAllocation;
}
