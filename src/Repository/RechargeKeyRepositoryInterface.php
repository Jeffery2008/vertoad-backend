<?php

declare(strict_types=1);

namespace VertoAD\Repository;

use DateTimeImmutable;
use VertoAD\Domain\Recharge\RechargeKey;

interface RechargeKeyRepositoryInterface
{
    public function transactional(callable $operation): mixed;

    public function store(RechargeKey $key): RechargeKey;

    public function findById(int $id): ?RechargeKey;

    public function findByKeyHash(string $keyHash): ?RechargeKey;

    public function markExpired(RechargeKey $key): RechargeKey;

    public function markRedeemed(
        RechargeKey $key,
        int $organizationId,
        int $redeemedByUserId,
        int $ledgerEntryId,
        DateTimeImmutable $redeemedAt,
    ): RechargeKey;
}
