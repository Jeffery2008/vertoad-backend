<?php

declare(strict_types=1);

namespace VertoAD\Repository\Serving;

use DateTimeImmutable;
use VertoAD\Domain\Serving\AdCandidate;

interface AdCandidateRepositoryInterface
{
    /**
     * @return list<AdCandidate>
     */
    public function eligibleCandidatesForSlot(
        int $siteId,
        int $slotId,
        ?array $size,
        ?DateTimeImmutable $now = null,
    ): array;
}
